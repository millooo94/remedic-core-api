<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Conventions\UpsertConventionPartnerWebProfileRequest;
use App\Http\Requests\Api\V1\Media\UploadMasterImageRequest;
use App\Http\Resources\Api\V1\Admin\ConventionPartnerWebProfileResource;
use App\Models\ConventionPartner;
use App\Services\ConventionPartnerWebContentService;
use App\Services\ManagedMediaService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AdminConventionPartnerWebProfileController extends Controller
{
    public function __construct(
        private readonly ConventionPartnerWebContentService $content,
        private readonly ManagedMediaService $media,
    ) {}

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = ConventionPartner::query()->with($this->relations());
        if ($search = $request->search()) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhereHas('webProfile', fn ($profile) => $profile->where('title', 'like', "%{$search}%")
                    ->orWhere('public_slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%")));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('is_web_enabled')) {
            $request->boolean('is_web_enabled')
                ? $query->whereHas('webProfile', fn ($profile) => $profile->where('is_web_enabled', true))
                : $query->where(fn ($nested) => $nested->whereDoesntHave('webProfile')->orWhereHas('webProfile', fn ($profile) => $profile->where('is_web_enabled', false)));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        return ConventionPartnerWebProfileResource::collection($query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate($request->perPage()));
    }

    public function show(ConventionPartner $convention): ConventionPartnerWebProfileResource
    {
        return new ConventionPartnerWebProfileResource($convention->load($this->relations()));
    }

    public function update(UpsertConventionPartnerWebProfileRequest $request, ConventionPartner $convention): ConventionPartnerWebProfileResource
    {
        $this->content->upsert($convention, $request->validated());

        return new ConventionPartnerWebProfileResource($convention->refresh()->load($this->relations()));
    }

    public function uploadTwitterImage(UploadMasterImageRequest $request, ConventionPartner $convention): ConventionPartnerWebProfileResource
    {
        $profile = $convention->webProfile;
        if ($profile === null) {
            throw ValidationException::withMessages(['twitter_image' => 'Salva prima la configurazione Web della convenzione.']);
        }
        $this->media->replace($profile, 'twitter_image_path', $request->file('image'), "convention-partner-web-profiles/{$profile->id}/twitter");

        return new ConventionPartnerWebProfileResource($convention->refresh()->load($this->relations()));
    }

    public function uploadOgImage(UploadMasterImageRequest $request, ConventionPartner $convention): ConventionPartnerWebProfileResource
    {
        $profile = $convention->webProfile;
        if ($profile === null) {
            throw ValidationException::withMessages(['og_image' => 'Salva prima la configurazione Web della convenzione.']);
        }
        $this->media->replace($profile, 'og_image_path', $request->file('image'), "convention-partner-web-profiles/{$profile->id}/og");

        return new ConventionPartnerWebProfileResource($convention->refresh()->load($this->relations()));
    }

    public function deleteOgImage(ConventionPartner $convention): ConventionPartnerWebProfileResource
    {
        if ($profile = $convention->webProfile) {
            $this->media->delete($profile, 'og_image_path', ["convention-partner-web-profiles/{$profile->id}/og"]);
        }

        return new ConventionPartnerWebProfileResource($convention->refresh()->load($this->relations()));
    }

    public function deleteTwitterImage(ConventionPartner $convention): ConventionPartnerWebProfileResource
    {
        if ($profile = $convention->webProfile) {
            $this->media->delete($profile, 'twitter_image_path', ["convention-partner-web-profiles/{$profile->id}/twitter"]);
        }

        return new ConventionPartnerWebProfileResource($convention->refresh()->load($this->relations()));
    }

    private function relations(): array
    {
        return ['webProfile.faqs'];
    }
}
