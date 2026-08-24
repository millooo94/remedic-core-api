<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Specializations\StoreSpecializationRequest;
use App\Http\Requests\Api\V1\Specializations\UpdateSpecializationRequest;
use App\Http\Resources\Api\V1\SpecializationResource;
use App\Models\Specialization;
use App\Services\ManagedMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SpecializationController extends Controller
{
    public function __construct(
        private readonly ManagedMediaService $media,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Specialization::query()->withCount(['professionals', 'services']);

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = (string) $request->query('sort', 'sort_order');
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'name' => $query->orderBy('name', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('sort_order', $direction)->orderBy('name'),
        };

        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        return SpecializationResource::collection($query->paginate($perPage));
    }

    public function options(): AnonymousResourceCollection
    {
        $specializations = Specialization::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return SpecializationResource::collection($specializations);
    }

    public function store(StoreSpecializationRequest $request): SpecializationResource
    {
        $specialization = DB::transaction(function () use ($request): Specialization {
            return Specialization::query()->create($request->validated());
        });

        return new SpecializationResource($specialization->loadCount(['professionals', 'services']));
    }

    public function show(Specialization $specialization): SpecializationResource
    {
        return new SpecializationResource($specialization->loadCount(['professionals', 'services']));
    }

    public function update(UpdateSpecializationRequest $request, Specialization $specialization): SpecializationResource
    {
        $specialization = DB::transaction(function () use ($request, $specialization): Specialization {
            $specialization->fill($this->validatedAttributes($request));
            $specialization->save();

            return $specialization;
        });

        return new SpecializationResource($specialization->loadCount(['professionals', 'services']));
    }

    public function destroy(Specialization $specialization): Response|JsonResponse
    {
        $dependencies = $specialization->deletionBlockers();
        if ($dependencies !== []) {
            return response()->json([
                'message' => 'La specializzazione è referenziata e non può essere eliminata.',
                'dependencies' => $dependencies,
            ], 409);
        }

        $iconPath = $specialization->icon_path;
        $imagePath = $specialization->featured_image_path;
        $specialization->delete();
        $this->media->deleteManagedFile($iconPath, [
            "specializations/{$specialization->id}/icons",
            'specializations/icons',
        ]);
        $this->media->deleteManagedFile($imagePath, ["specializations/{$specialization->id}/images"]);

        return response()->noContent();
    }

    private function validatedAttributes(StoreSpecializationRequest $request): array
    {
        $payload = $request->validated();
        unset($payload['icon_svg'], $payload['remove_icon']);

        return $payload;
    }
}
