<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Checkups\StoreCheckupRequest;
use App\Http\Requests\Api\V1\Checkups\UpdateCheckupRequest;
use App\Http\Resources\Api\V1\CheckupResource;
use App\Models\Checkup;
use App\Models\Redirect;
use App\Services\CheckupCatalogService;
use App\Support\Filters\CheckupFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CheckupController extends Controller
{
    public function __construct(
        private readonly CheckupCatalogService $service,
        private readonly CheckupFilters $filters,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'search' => ['nullable', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
            'specialization_name' => ['nullable', 'string', 'max:190'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
            'archive_state' => ['nullable', 'in:active,archived,all'],
        ]);
        $query = Checkup::query();
        $this->filters->apply($query, $filters);

        $checkups = $query
            ->orderBy('display_name')
            ->get()
            ->map(fn (Checkup $checkup): Checkup => $this->service->loadForResource($checkup));

        return CheckupResource::collection($checkups);
    }

    public function store(StoreCheckupRequest $request): CheckupResource
    {
        $checkup = $this->service->create($request->validated());

        return new CheckupResource($this->service->loadForResource($checkup, true));
    }

    public function show(Checkup $checkup): CheckupResource
    {
        return new CheckupResource($this->service->loadForResource($checkup, true));
    }

    public function update(UpdateCheckupRequest $request, Checkup $checkup): CheckupResource
    {
        $checkup = $this->service->update($checkup, $request->validated());

        return new CheckupResource($this->service->loadForResource($checkup, true));
    }

    public function destroy(Checkup $checkup): Response
    {
        $this->service->delete($checkup);

        return response()->noContent();
    }

    public function restore(int $checkup): CheckupResource
    {
        $model = Checkup::withTrashed()->findOrFail($checkup);
        $model->restore();

        return new CheckupResource($this->service->loadForResource($model, true));
    }

    public function forceDestroy(int $checkup): Response|JsonResponse
    {
        $model = Checkup::withTrashed()->findOrFail($checkup);
        $profileId = $model->webProfile()->value('id');
        $dependencies = collect([
            'services' => $model->items()->count(),
            'web_profile' => $profileId === null ? 0 : 1,
            'media' => collect([$model->featured_image_path, $model->icon_path])
                ->filter(fn ($path) => trim((string) $path) !== '')->count(),
            'redirects' => $profileId === null ? 0 : Redirect::query()
                ->where('source_type', Redirect::SOURCE_TYPE_CHECKUP_WEB_PROFILE)
                ->where('source_id', $profileId)->count(),
        ])->filter(fn (int $count): bool => $count > 0);

        if ($dependencies->isNotEmpty()) {
            return response()->json([
                'message' => 'Il Check-up è utilizzato e non può essere eliminato definitivamente. Puoi archiviarlo.',
                'dependencies' => $dependencies->all(),
            ], Response::HTTP_CONFLICT);
        }

        DB::transaction(fn () => $model->forceDelete());

        return response()->noContent();
    }
}
