<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Services\StoreServiceRequest;
use App\Http\Requests\Api\V1\Services\UpdateServiceRequest;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Specialization;
use App\Services\ManagedMediaService;
use App\Support\Filters\ServiceFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceFilters $filters,
        private readonly ManagedMediaService $media,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Service::query()->with(['category', 'aliases', 'professionalServices.professional.specializations', 'specializations']);
        $this->filters->apply($query, $request->all());

        $services = $query->orderBy('display_name')->get();

        return ServiceResource::collection($services);
    }

    public function store(StoreServiceRequest $request): ServiceResource
    {
        $service = DB::transaction(fn () => $this->persist(new Service, $request->validated()));

        return new ServiceResource($service->load(['category', 'aliases', 'professionalServices.professional.specializations', 'specializations']));
    }

    public function show(Service $service): ServiceResource
    {
        return new ServiceResource($service->load(['category', 'aliases', 'professionalServices.professional.specializations', 'specializations']));
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service = DB::transaction(fn () => $this->persist($service, $request->validated()));

        return new ServiceResource($service->load(['category', 'aliases', 'professionalServices.professional.specializations', 'specializations']));
    }

    public function destroy(Service $service): Response|JsonResponse
    {
        if ($service->checkupItems()->exists()) {
            return response()->json([
                'message' => 'La prestazione non puo essere eliminata perche e inclusa in uno o piu Check-up. Disattivala oppure rimuovila prima dai Check-up.',
            ], Response::HTTP_CONFLICT);
        }

        $imagePath = $service->featured_image_path;
        $service->delete();
        $this->media->deleteManagedFile($imagePath, ["services/{$service->id}/images"]);

        return response()->noContent();
    }

    private function persist(Service $service, array $payload): Service
    {
        $specializationIds = $this->extractSpecializationIds($payload);
        $primarySpecialization = $this->resolvePrimarySpecialization($specializationIds, $payload);
        $displayName = trim((string) ($payload['display_name'] ?? ''));
        $canonicalName = trim((string) ($payload['canonical_name'] ?? ''));
        if ($canonicalName === '') {
            $canonicalName = $displayName;
        }

        $baseSlug = Str::slug($displayName ?: $canonicalName);
        $resolvedCategory = $this->resolveLegacyCategory($primarySpecialization, $payload);
        $resolvedCategoryId = $resolvedCategory?->id;
        $categoryPrefix = $primarySpecialization?->slug ?: ($resolvedCategory?->slug ?: 'servizio');

        $service->fill([
            'category_id' => $resolvedCategoryId,
            'canonical_name' => $canonicalName,
            'display_name' => $displayName,
            'importo_prestazione' => array_key_exists('importo_prestazione', $payload)
                ? $payload['importo_prestazione']
                : $service->importo_prestazione,
            'slug' => $service->exists ? $service->slug : $this->buildUniqueSlug($service, $categoryPrefix, $baseSlug),
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

        $specializationSync = collect($specializationIds)
            ->values()
            ->mapWithKeys(fn (int $id, int $index): array => [
                $id => [
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                ],
            ])
            ->all();

        $service->specializations()->sync($specializationSync);

        return $service;
    }

    /**
     * @return array<int, int>
     */
    private function extractSpecializationIds(array $payload): array
    {
        return collect($payload['specialization_ids'] ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $specializationIds
     */
    private function resolvePrimarySpecialization(array $specializationIds, array $payload): ?Specialization
    {
        if ($specializationIds !== []) {
            return Specialization::query()->find($specializationIds[0]);
        }

        $categoryName = trim((string) ($payload['category_name'] ?? ''));
        if ($categoryName === '') {
            return null;
        }

        return Specialization::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($categoryName)])
            ->first();
    }

    private function resolveLegacyCategory(?Specialization $specialization, array $payload): ?ServiceCategory
    {
        if ($specialization !== null) {
            return ServiceCategory::query()->firstOrCreate(
                ['slug' => $specialization->slug],
                [
                    'name' => $specialization->name,
                    'is_active' => $specialization->is_active,
                ],
            );
        }

        if (! empty($payload['category_id'])) {
            $existing = ServiceCategory::query()->find($payload['category_id']);
            if ($existing) {
                return $existing;
            }
        }

        $categoryName = trim((string) ($payload['category_name'] ?? ''));
        if ($categoryName === '') {
            return null;
        }

        return ServiceCategory::query()->firstOrCreate(
            ['slug' => Str::slug($categoryName)],
            [
                'name' => $categoryName,
                'is_active' => true,
            ],
        );
    }

    private function buildUniqueSlug(Service $service, string $categoryPrefix, string $baseSlug): string
    {
        $rootSlug = Str::slug(trim($categoryPrefix.' '.$baseSlug)) ?: 'servizio';
        $candidate = $rootSlug;
        $suffix = 2;

        while (
            Service::query()
                ->when($service->exists, fn ($query) => $query->whereKeyNot($service->id))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $rootSlug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
