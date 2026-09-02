<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PromotionValidityBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Media\UploadMasterImageRequest;
use App\Http\Resources\Api\V1\PromotionResource;
use App\Models\Checkup;
use App\Models\Promotion;
use App\Models\Service;
use App\Services\ManagedMediaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PromotionController extends Controller
{
    public function __construct(private readonly ManagedMediaService $media) {}

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:190'], 'target_type' => ['nullable', Rule::in(['service', 'checkup'])], 'lifecycle_status' => ['nullable', Rule::in(['inactive', 'scheduled', 'active', 'expired'])], 'archive_state' => ['nullable', Rule::in(['active', 'archived', 'all'])]]);
        $query = Promotion::query()->with(['service', 'checkup']);
        match ($filters['archive_state'] ?? 'active') {
            'archived' => $query->onlyTrashed(), 'all' => $query->withTrashed(), default => null,
        };
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where('name', 'like', "%{$search}%");
        }
        if (($filters['target_type'] ?? null) === 'service') {
            $query->whereNotNull('service_id');
        } elseif (($filters['target_type'] ?? null) === 'checkup') {
            $query->whereNotNull('checkup_id');
        }
        $promotions = $query->orderByDesc('start_at')->get();
        if ($status = $filters['lifecycle_status'] ?? null) {
            $promotions = $promotions->filter(fn (Promotion $promotion): bool => $promotion->lifecycleStatus() === $status)->values();
        }

        return PromotionResource::collection($promotions);
    }

    public function show(Promotion $promotion): PromotionResource
    {
        return new PromotionResource($promotion->load(['service', 'checkup']));
    }

    public function store(Request $request): PromotionResource
    {
        $promotion = DB::transaction(fn () => $this->persist(new Promotion, $request));

        return new PromotionResource($promotion->load(['service', 'checkup']));
    }

    public function update(Request $request, Promotion $promotion): PromotionResource
    {
        $promotion = DB::transaction(fn () => $this->persist($promotion, $request));

        return new PromotionResource($promotion->load(['service', 'checkup']));
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();

        return response()->noContent();
    }

    public function restore(int $promotion): PromotionResource
    {
        $model = Promotion::withTrashed()->findOrFail($promotion);
        if ($model->is_active) {
            $this->ensureNoOverlap($model->service_id, $model->checkup_id, $model->start_at, $model->end_at, $model->id);
        }
        $model->restore();

        return new PromotionResource($model->load(['service', 'checkup']));
    }

    public function uploadImage(UploadMasterImageRequest $request, Promotion $promotion): PromotionResource
    {
        $this->media->replace($promotion, 'image_path', $request->file('image'), "promotions/{$promotion->id}/images");

        return new PromotionResource($promotion->refresh()->load(['service', 'checkup']));
    }

    public function deleteImage(Promotion $promotion): PromotionResource
    {
        $this->media->delete($promotion, 'image_path', ["promotions/{$promotion->id}/images"]);

        return new PromotionResource($promotion->refresh()->load(['service', 'checkup']));
    }

    public function targets(): array
    {
        return [
            'data' => [
                'services' => Service::query()->orderBy('display_name')->get()->map(fn (Service $service): array => ['id' => $service->id, 'name' => $service->display_name, 'standard_price' => $service->importo_prestazione, 'is_operational' => (bool) $service->is_active])->all(),
                'checkups' => Checkup::query()->with('items')->orderBy('display_name')->get()->map(fn (Checkup $checkup): array => ['id' => $checkup->id, 'name' => $checkup->display_name, 'standard_price' => $checkup->price_amount, 'is_operational' => $checkup->isOperationallyAvailable(), 'items_count' => $checkup->items->count()])->all(),
            ],
        ];
    }

    private function persist(Promotion $promotion, Request $request): Promotion
    {
        $data = $request->validate($this->rules());
        $serviceId = $data['service_id'] ?? null;
        $checkupId = $data['checkup_id'] ?? null;
        if (($serviceId === null) === ($checkupId === null)) {
            throw ValidationException::withMessages(['target' => 'Seleziona esattamente una Prestazione o un Check-up.']);
        }
        if (($data['end_at'] ?? null) !== null && ! Carbon::parse($data['end_at'])->gt(Carbon::parse($data['start_at']))) {
            throw ValidationException::withMessages(['end_at' => 'La data di fine deve essere successiva alla data di inizio.']);
        }
        if ($serviceId !== null && ! Service::query()->whereKey($serviceId)->exists()) {
            throw ValidationException::withMessages(['service_id' => 'La prestazione selezionata non è disponibile.']);
        }
        if ($checkupId !== null && ! Checkup::query()->whereKey($checkupId)->exists()) {
            throw ValidationException::withMessages(['checkup_id' => 'Il Check-up selezionato non è disponibile.']);
        }
        $data['is_active'] = (bool) ($data['is_active'] ?? $promotion->is_active ?? false);
        if ($data['is_active']) {
            $this->ensureNoOverlap($serviceId, $checkupId, Carbon::parse($data['start_at']), Carbon::parse($data['end_at']), $promotion->exists ? $promotion->id : null);
        }
        $promotion->fill($data)->save();

        return $promotion;
    }

    private function ensureNoOverlap(?int $serviceId, ?int $checkupId, Carbon $startAt, Carbon $endAt, ?int $ignoreId = null): void
    {
        $exists = Promotion::query()->where('is_active', true)->when($serviceId !== null, fn ($query) => $query->where('service_id', $serviceId))->when($checkupId !== null, fn ($query) => $query->where('checkup_id', $checkupId))->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))->where('start_at', '<', $endAt)->where('end_at', '>', $startAt)->exists();
        if ($exists) {
            throw ValidationException::withMessages(['start_at' => 'Esiste già una promozione attiva per questa prestazione/check-up nel periodo selezionato.']);
        }
    }

    private function rules(): array
    {
        return ['name' => ['required', 'string', 'max:190'], 'service_id' => ['nullable', 'integer'], 'checkup_id' => ['nullable', 'integer'], 'promotional_price' => ['required', 'numeric', 'min:0'], 'start_at' => ['required', 'date'], 'end_at' => ['required', 'date'], 'validity_basis' => ['required', Rule::enum(PromotionValidityBasis::class)], 'is_active' => ['sometimes', 'boolean'], 'internal_notes' => ['nullable', 'string', 'max:5000']];
    }
}
