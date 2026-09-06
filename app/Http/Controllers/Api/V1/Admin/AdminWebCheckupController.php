<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Checkups\UpsertCheckupWebProfileRequest;
use App\Http\Requests\Api\V1\Admin\WebCheckupIndexRequest;
use App\Http\Requests\Api\V1\Media\UploadMasterImageRequest;
use App\Http\Resources\Api\V1\Admin\CheckupWebProfileResource;
use App\Models\Checkup;
use App\Models\Redirect;
use App\Services\AutomaticSlugRedirectService;
use App\Services\CheckupWebContentService;
use App\Services\ManagedMediaService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AdminWebCheckupController extends Controller
{
    public function __construct(
        private readonly CheckupWebContentService $content,
        private readonly AutomaticSlugRedirectService $redirects,
        private readonly ManagedMediaService $media,
    ) {}

    public function index(WebCheckupIndexRequest $request): AnonymousResourceCollection
    {
        $query = Checkup::query();
        $query->with($this->relations());
        if ($search = $request->search()) {
            $query->where(fn ($builder) => $builder->where('display_name', 'like', "%{$search}%")
                ->orWhereHas('webProfile', fn ($profile) => $profile->where('public_slug', 'like', "%{$search}%")
                    ->orWhere('category_label', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%")));
        }
        if ($request->has('is_web_enabled')) {
            $query->whereHas('webProfile', fn ($profile) => $profile
                ->where('is_web_enabled', $request->boolean('is_web_enabled')));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->has('specialization_id')) {
            $query->whereHas('items.service.specializations', fn ($specialization) => $specialization->whereKey((int) $request->validated('specialization_id')));
        }
        if ($request->has('professional_id')) {
            $query->whereHas('items.service.professionalServices', fn ($professionalService) => $professionalService->where('professional_id', (int) $request->validated('professional_id')));
        }
        if ($request->has('is_operationally_available')) {
            $request->boolean('is_operationally_available')
                ? $query->operationallyAvailable()
                : $query->where(fn ($nested) => $nested
                    ->whereNotNull('deleted_at')
                    ->orWhere('is_active', false)
                    ->orWhereDoesntHave('items')
                    ->orWhereHas('items', fn ($item) => $item
                        ->whereDoesntHave('service', fn ($service) => $service
                            ->whereNull('services.deleted_at')
                            ->where('is_active', true))));
        }

        match ($request->sort()) {
            'updated_at' => $query->orderBy('updated_at', $request->direction()),
            default => $query->orderBy('display_name', $request->direction()),
        };

        return CheckupWebProfileResource::collection($query->paginate($request->perPage()));
    }

    public function show(Checkup $checkup): CheckupWebProfileResource
    {
        if ($checkup->webProfile !== null) {
            $this->content->initializeSections($checkup->webProfile);
        }

        return new CheckupWebProfileResource($this->load($checkup));
    }

    public function update(UpsertCheckupWebProfileRequest $request, Checkup $checkup): CheckupWebProfileResource
    {
        $previousSlug = $checkup->webProfile?->public_slug;
        $profile = $this->content->upsert($checkup, $request->validated());
        if ($previousSlug !== null && $previousSlug !== $profile->public_slug) {
            $this->redirects->sync(
                Redirect::SOURCE_TYPE_CHECKUP_WEB_PROFILE,
                $profile->id,
                '/check-up/'.$previousSlug,
                '/check-up/'.$profile->public_slug,
            );
        }

        return new CheckupWebProfileResource($this->load($checkup));
    }

    public function uploadTwitterImage(UploadMasterImageRequest $request, Checkup $checkup): CheckupWebProfileResource
    {
        $profile = $checkup->webProfile;
        if ($profile === null) {
            throw ValidationException::withMessages(['twitter_image' => 'Salva prima la configurazione Web del Check-up.']);
        }
        $this->media->replace($profile, 'twitter_image_path', $request->file('image'), "checkup-web-profiles/{$profile->id}/twitter");

        return new CheckupWebProfileResource($this->load($checkup));
    }

    public function deleteTwitterImage(Checkup $checkup): CheckupWebProfileResource
    {
        $profile = $checkup->webProfile;
        if ($profile !== null) {
            $this->media->delete($profile, 'twitter_image_path', ["checkup-web-profiles/{$profile->id}/twitter"]);
        }

        return new CheckupWebProfileResource($this->load($checkup));
    }

    private function load(Checkup $checkup): Checkup
    {
        $model = $checkup->refresh()->load($this->relations());
        $model->setRelation('relatedWebCheckups', Checkup::query()->effectivelyVisible()
            ->with('webProfile')->whereKeyNot($model->id)
            ->orderBy('display_name')->orderBy('id')->limit(3)->get());

        return $model;
    }

    private function relations(): array
    {
        return [
            'webProfile.sections', 'webProfile.faqs', 'items.service.specializations',
            'items.service.professionalServices.professional',
        ];
    }
}
