<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Services\UpsertServiceWebProfileRequest;
use App\Http\Resources\Api\V1\Admin\ServiceWebProfileResource;
use App\Models\Redirect;
use App\Models\Service;
use App\Services\AutomaticSlugRedirectService;
use App\Services\ServiceWebContentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminWebServiceController extends Controller
{
    public function __construct(
        private readonly ServiceWebContentService $content,
        private readonly AutomaticSlugRedirectService $redirects,
    ) {}

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = Service::query();
        match ($request->validated('archive_state', 'active')) {
            'archived' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => null,
        };
        $query->with($this->relations());

        if ($search = $request->search()) {
            $query->where(fn ($builder) => $builder
                ->where('display_name', 'like', "%{$search}%")
                ->orWhere('canonical_name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhereHas('webProfile', fn ($profile) => $profile
                    ->where('public_slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%")));
        }

        $enabledFilter = $request->has('is_web_enabled')
            ? 'is_web_enabled'
            : ($request->has('is_web_active') ? 'is_web_active' : null);
        if ($enabledFilter !== null) {
            $enabled = $request->boolean($enabledFilter);
            $query->whereHas('webProfile', fn ($profile) => $profile->where('is_web_enabled', $enabled));
        }

        if ($request->has('is_configured')) {
            $request->boolean('is_configured')
                ? $query->whereHas('webProfile')
                : $query->whereDoesntHave('webProfile');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('effective_public_visibility')) {
            $request->boolean('effective_public_visibility')
                ? $query->effectivelyVisible()
                : $query->where(fn ($nested) => $nested
                    ->whereNotNull('deleted_at')
                    ->orWhere('is_active', false)
                    ->orWhereDoesntHave('webProfile', fn ($profile) => $profile->where('is_web_enabled', true)));
        }

        match ($request->sort()) {
            'canonical_name' => $query->orderBy('canonical_name', $request->direction()),
            'updated_at' => $query->orderBy('updated_at', $request->direction()),
            default => $query->orderBy('display_name', $request->direction()),
        };

        return ServiceWebProfileResource::collection($query->paginate($request->perPage()));
    }

    public function show(Service $service): ServiceWebProfileResource
    {
        if ($service->webProfile !== null) {
            $this->content->initializeSections($service->webProfile);
        }

        return new ServiceWebProfileResource($this->load($service));
    }

    public function update(UpsertServiceWebProfileRequest $request, Service $service): ServiceWebProfileResource
    {
        $previousSlug = $service->webProfile?->public_slug;
        $profile = $this->content->upsert($service, $request->validated());

        if ($previousSlug !== null && $previousSlug !== $profile->public_slug) {
            $this->redirects->sync(
                Redirect::SOURCE_TYPE_SERVICE_WEB_PROFILE,
                $profile->id,
                '/prestazioni/'.$previousSlug,
                '/prestazioni/'.$profile->public_slug,
            );
        }

        return new ServiceWebProfileResource($this->load($service));
    }

    private function load(Service $service): Service
    {
        return $service->refresh()->load($this->relations());
    }

    private function relations(): array
    {
        return [
            'webProfile.sections',
            'webProfile.faqs',
            'specializations',
            'professionalServices.professional',
        ];
    }
}
