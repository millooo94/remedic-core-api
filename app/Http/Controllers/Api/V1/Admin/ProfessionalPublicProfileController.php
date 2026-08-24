<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles\StoreProfessionalPublicProfileRequest;
use App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles\UpdateEquipeSectionsRequest;
use App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles\UpdateProfessionalPublicProfileRequest;
use App\Http\Resources\Api\V1\Admin\ProfessionalPublicProfileResource;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Services\AutomaticSlugRedirectService;
use App\Services\EquipeContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProfessionalPublicProfileController extends Controller
{
    public function __construct(
        private readonly EquipeContentService $content,
        private readonly AutomaticSlugRedirectService $redirects,
    ) {}

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = ProfessionalPublicProfile::query()->with($this->relations());

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%")
                    ->orWhereHas('professional', fn ($professionalQuery) => $professionalQuery
                        ->where('full_name', 'like', "%{$search}%"));
            });
        }

        if ($request->has('is_web_enabled')) {
            $query->where('is_web_enabled', $request->boolean('is_web_enabled'));
        } elseif ($request->has('is_active')) {
            $query->where('is_web_enabled', $request->boolean('is_active'));
        }

        $sort = $request->sort();
        $direction = $request->direction();
        match ($sort) {
            'slug' => $query->orderBy('slug', $direction),
            'sort_order' => $query->orderBy('sort_order', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->join('professionals', 'professionals.id', '=', 'professional_public_profiles.professional_id')
                ->orderBy('professionals.full_name', $direction)
                ->select('professional_public_profiles.*'),
        };

        return ProfessionalPublicProfileResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreProfessionalPublicProfileRequest $request): JsonResponse
    {
        $profile = DB::transaction(function () use ($request): ProfessionalPublicProfile {
            $payload = $request->validated();
            $contentPayload = $this->extractContentPayload($payload);
            $profile = ProfessionalPublicProfile::query()->create($payload + [
                'is_active' => (bool) ($payload['is_web_enabled'] ?? false),
            ]);
            $this->content->initializeSections($profile);
            $this->content->syncTypedContent($profile, $contentPayload);

            return $profile;
        });

        return (new ProfessionalPublicProfileResource($this->loadProfile($profile)))
            ->response()->setStatusCode(201);
    }

    public function show(ProfessionalPublicProfile $professionalPublicProfile): ProfessionalPublicProfileResource
    {
        $this->content->initializeSections($professionalPublicProfile);

        return new ProfessionalPublicProfileResource($this->loadProfile($professionalPublicProfile));
    }

    public function update(
        UpdateProfessionalPublicProfileRequest $request,
        ProfessionalPublicProfile $professionalPublicProfile
    ): ProfessionalPublicProfileResource {
        $profile = DB::transaction(function () use ($request, $professionalPublicProfile): ProfessionalPublicProfile {
            $previousSlug = $professionalPublicProfile->slug;
            $payload = $request->validated();
            $contentPayload = $this->extractContentPayload($payload);
            $professionalPublicProfile->fill($payload);
            $professionalPublicProfile->is_active = (bool) ($professionalPublicProfile->is_web_enabled ?? false);
            $professionalPublicProfile->save();
            $this->content->initializeSections($professionalPublicProfile);
            $this->content->syncTypedContent($professionalPublicProfile, $contentPayload);

            if ($previousSlug !== $professionalPublicProfile->slug) {
                $this->redirects->sync(
                    Redirect::SOURCE_TYPE_EQUIPE_PROFILE,
                    $professionalPublicProfile->id,
                    '/equipe/'.$previousSlug,
                    '/equipe/'.$professionalPublicProfile->slug
                );
            }

            return $professionalPublicProfile;
        });

        return new ProfessionalPublicProfileResource($this->loadProfile($profile));
    }

    public function updateSections(
        UpdateEquipeSectionsRequest $request,
        ProfessionalPublicProfile $professionalPublicProfile
    ): ProfessionalPublicProfileResource {
        $this->content->updateSections($professionalPublicProfile, $request->validated('sections'));

        return new ProfessionalPublicProfileResource($this->loadProfile($professionalPublicProfile));
    }

    public function destroy(ProfessionalPublicProfile $professionalPublicProfile): Response
    {
        $professionalPublicProfile->delete();

        return response()->noContent();
    }

    private function extractContentPayload(array &$payload): array
    {
        $keys = ['hero_competency_ids', 'approach_principles', 'competencies', 'scientific_activities'];
        $content = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                $content[$key] = $payload[$key];
                unset($payload[$key]);
            }
        }

        return $content;
    }

    private function loadProfile(ProfessionalPublicProfile $profile): ProfessionalPublicProfile
    {
        return $profile->refresh()->load($this->relations());
    }

    private function relations(): array
    {
        return [
            'sections',
            'competencies',
            'heroCompetencies',
            'approachPrinciples',
            'scientificActivities',
            'professional.specializations',
            'professional.professionalServices.service',
            'professional.degrees',
            'professional.academicSpecializations',
            'professional.boardRegistrations',
            'professional.careerExperiences',
        ];
    }
}
