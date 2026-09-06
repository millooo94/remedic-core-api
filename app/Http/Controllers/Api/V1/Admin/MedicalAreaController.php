<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\MedicalAreas\UpsertMedicalAreaRequest;
use App\Http\Requests\Api\V1\Media\UploadMasterImageRequest;
use App\Http\Resources\Api\V1\Admin\MedicalAreaResource;
use App\Models\Redirect;
use App\Models\Specialization;
use App\Services\AutomaticSlugRedirectService;
use App\Services\ManagedMediaService;
use App\Services\MedicalAreaContentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class MedicalAreaController extends Controller
{
    public function __construct(
        private readonly MedicalAreaContentService $content,
        private readonly AutomaticSlugRedirectService $redirects,
        private readonly ManagedMediaService $media,
    ) {}

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = Specialization::query()->with($this->relations())->withCount(['services', 'professionals']);
        if ($search = $request->search()) {
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhereHas('webProfile', fn ($profile) => $profile
                    ->where('slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%")));
        }
        if ($request->has('is_web_enabled')) {
            $enabled = $request->boolean('is_web_enabled');
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
                    ->where('is_active', false)
                    ->orWhereDoesntHave('webProfile', fn ($profile) => $profile->where('is_web_enabled', true)));
        }

        return MedicalAreaResource::collection(
            $query->orderBy('name', $request->direction())->paginate($request->perPage())
        );
    }

    public function show(Specialization $specialization): MedicalAreaResource
    {
        if ($specialization->webProfile !== null) {
            $this->content->initializeSections($specialization->webProfile);
        }

        return new MedicalAreaResource($this->load($specialization));
    }

    public function update(UpsertMedicalAreaRequest $request, Specialization $specialization): MedicalAreaResource
    {
        $previousSlug = $specialization->webProfile?->slug;
        $profile = $this->content->upsert($specialization, $request->validated());

        if ($previousSlug !== null && $previousSlug !== $profile->slug) {
            $this->redirects->sync(
                Redirect::SOURCE_TYPE_MEDICAL_AREA,
                $profile->id,
                '/aree-mediche/'.$previousSlug,
                '/aree-mediche/'.$profile->slug
            );
        }

        return new MedicalAreaResource($this->load($specialization));
    }

    public function uploadTwitterImage(UploadMasterImageRequest $request, Specialization $specialization): MedicalAreaResource
    {
        $profile = $specialization->webProfile;
        if ($profile === null) {
            throw ValidationException::withMessages(['twitter_image' => 'Salva prima la configurazione Web dell’Area medica.']);
        }
        $this->media->replace($profile, 'twitter_image_path', $request->file('image'), "specialization-web-profiles/{$profile->id}/twitter");

        return new MedicalAreaResource($this->load($specialization));
    }

    public function deleteTwitterImage(Specialization $specialization): MedicalAreaResource
    {
        $profile = $specialization->webProfile;
        if ($profile !== null) {
            $this->media->delete($profile, 'twitter_image_path', ["specialization-web-profiles/{$profile->id}/twitter"]);
        }

        return new MedicalAreaResource($this->load($specialization));
    }

    private function load(Specialization $specialization): Specialization
    {
        return $specialization->refresh()->load($this->relations())->loadCount(['services', 'professionals']);
    }

    private function relations(): array
    {
        return [
            'webProfile.sections',
            'webProfile.faqs',
            'services.webProfile',
            'professionals.publicProfile',
        ];
    }
}
