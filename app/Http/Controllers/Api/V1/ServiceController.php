<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Services\StoreServiceRequest;
use App\Http\Requests\Api\V1\Services\UpdateServiceRequest;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Filters\ServiceFilters;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceFilters $filters,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Service::query()->with(['category', 'aliases', 'professionalServices.professional.areas']);
        $this->filters->apply($query, $request->all());

        $services = $query->orderBy('display_name')->get();

        return ServiceResource::collection($services);
    }

    public function store(StoreServiceRequest $request): ServiceResource
    {
        $service = DB::transaction(fn () => $this->persist(new Service(), $request->validated()));

        return new ServiceResource($service->load(['category', 'aliases', 'professionalServices.professional.areas']));
    }

    public function show(Service $service): ServiceResource
    {
        return new ServiceResource($service->load(['category', 'aliases', 'professionalServices.professional.areas']));
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service = DB::transaction(fn () => $this->persist($service, $request->validated()));

        return new ServiceResource($service->load(['category', 'aliases', 'professionalServices.professional.areas']));
    }

    public function destroy(Service $service): Response
    {
        $service->delete();

        return response()->noContent();
    }

    private function persist(Service $service, array $payload): Service
    {
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $canonicalName = trim((string) ($payload['canonical_name'] ?? ''));
        if ($canonicalName === '') {
            $canonicalName = $displayName;
        }
        $baseSlug = Str::slug($displayName ?: $canonicalName);
        $resolvedCategory = $this->resolveCategory($payload);
        $resolvedCategoryId = $resolvedCategory?->id;
        $categoryPrefix = $resolvedCategory?->slug ?: 'servizio';

        $service->fill([
            'category_id' => $resolvedCategoryId,
            'canonical_name' => $canonicalName,
            'display_name' => $displayName,
            'slug' => $service->exists ? $service->slug : Str::slug($categoryPrefix.' '.$baseSlug),
            'description' => null,
            'default_duration_minutes' => $payload['default_duration_minutes'] ?? null,
            'is_active' => $payload['is_active'] ?? true,
            'notes' => $payload['notes'] ?? null,
        ]);
        $service->save();

        $service->aliases()->delete();

        foreach ($payload['aliases'] ?? [] as $alias) {
            $aliasName = trim($alias['alias_name']);

            if ($aliasName === '' || strcasecmp($aliasName, $displayName) === 0) {
                continue;
            }

            $service->aliases()->create([
                'alias_name' => $aliasName,
                'alias_slug' => Str::slug($aliasName),
                'source_label' => $alias['source_label'] ?? null,
            ]);
        }

        $service->professionalServices()->delete();

        foreach ($payload['professional_services'] ?? [] as $link) {
            $service->professionalServices()->create([
                'professional_id' => $link['professional_id'],
                'duration_minutes' => $link['duration_minutes'] ?? null,
                'price_amount' => $link['price_amount'] ?? null,
                'is_visible_public' => $link['is_visible_public'] ?? true,
                'is_bookable_online' => $link['is_bookable_online'] ?? false,
                'source_platform' => $link['source_platform'] ?? null,
                'source_notes' => $link['source_notes'] ?? null,
                'is_active' => $link['is_active'] ?? true,
            ]);
        }

        return $service;
    }

    private function resolveCategory(array $payload): ?ServiceCategory
    {
        if (!empty($payload['category_id'])) {
            $existing = ServiceCategory::query()->find($payload['category_id']);
            if ($existing) {
                return $existing;
            }
        }

        $categoryName = trim((string) ($payload['category_name'] ?? ''));
        if ($categoryName === '') {
            return null;
        }

        $slug = Str::slug($categoryName);

        return ServiceCategory::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $categoryName,
                'is_active' => true,
            ],
        );
    }
}
