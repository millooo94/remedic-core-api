<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\PersistsSectionsAndFaqs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles\StoreProfessionalPublicProfileRequest;
use App\Http\Requests\Api\V1\Admin\ProfessionalPublicProfiles\UpdateProfessionalPublicProfileRequest;
use App\Http\Resources\Api\V1\Admin\ProfessionalPublicProfileResource;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProfessionalPublicProfileController extends Controller
{
    use PersistsSectionsAndFaqs;

    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = ProfessionalPublicProfile::query()
            ->with([
                'professional',
                'sections',
                'faqs',
                'professional.degrees',
                'professional.academicSpecializations',
                'professional.boardRegistrations',
            ]);

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('slug', 'like', "%{$search}%")
                    ->orWhere('seo_title', 'like', "%{$search}%")
                    ->orWhereHas('professional', function ($professionalQuery) use ($search): void {
                        $professionalQuery
                            ->where('full_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
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
        $profile = DB::transaction(fn () => $this->persist(new ProfessionalPublicProfile(), $request->validated()));

        return (new ProfessionalPublicProfileResource($this->loadProfile($profile)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ProfessionalPublicProfile $professionalPublicProfile): ProfessionalPublicProfileResource
    {
        return new ProfessionalPublicProfileResource($this->loadProfile($professionalPublicProfile));
    }

    public function update(UpdateProfessionalPublicProfileRequest $request, ProfessionalPublicProfile $professionalPublicProfile): ProfessionalPublicProfileResource
    {
        $profile = DB::transaction(fn () => $this->persist($professionalPublicProfile, $request->validated()));

        return new ProfessionalPublicProfileResource($this->loadProfile($profile));
    }

    public function destroy(ProfessionalPublicProfile $professionalPublicProfile): Response
    {
        $professionalPublicProfile->delete();

        return response()->noContent();
    }

    private function persist(ProfessionalPublicProfile $profile, array $payload): ProfessionalPublicProfile
    {
        $relationsPayload = [
            'sections' => $payload['sections'] ?? [],
            'faqs' => $payload['faqs'] ?? [],
            'degrees' => $payload['degrees'] ?? [],
            'academic_specializations' => $payload['academic_specializations'] ?? [],
            'board_registrations' => $payload['board_registrations'] ?? [],
        ];

        unset(
            $payload['sections'],
            $payload['faqs'],
            $payload['degrees'],
            $payload['academic_specializations'],
            $payload['board_registrations'],
        );

        $profile->fill($payload);
        $profile->save();

        $this->persistSectionsAndFaqs($profile, $relationsPayload);

        /** @var Professional $professional */
        $professional = $profile->professional()->firstOrFail();

        $professional->degrees()->delete();
        foreach ($relationsPayload['degrees'] as $index => $degree) {
            $professional->degrees()->create([
                'title' => $degree['title'],
                'awarded_on' => $degree['awarded_on'] ?? null,
                'sort_order' => $degree['sort_order'] ?? $index,
            ]);
        }

        $professional->academicSpecializations()->delete();
        foreach ($relationsPayload['academic_specializations'] as $index => $specialization) {
            $professional->academicSpecializations()->create([
                'title' => $specialization['title'],
                'awarded_on' => $specialization['awarded_on'] ?? null,
                'sort_order' => $specialization['sort_order'] ?? $index,
            ]);
        }

        $professional->boardRegistrations()->delete();
        foreach ($relationsPayload['board_registrations'] as $index => $registration) {
            $professional->boardRegistrations()->create([
                'board_name' => $registration['board_name'],
                'registered_on' => $registration['registered_on'] ?? null,
                'sort_order' => $registration['sort_order'] ?? $index,
            ]);
        }

        return $profile->fresh();
    }

    private function loadProfile(ProfessionalPublicProfile $profile): ProfessionalPublicProfile
    {
        return $profile->load([
            'professional',
            'sections',
            'faqs',
            'professional.degrees',
            'professional.academicSpecializations',
            'professional.boardRegistrations',
        ]);
    }
}
