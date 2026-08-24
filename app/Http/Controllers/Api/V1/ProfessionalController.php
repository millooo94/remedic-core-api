<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProfessionalGender;
use App\Enums\ProfessionalSubjectType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Professionals\StoreProfessionalRequest;
use App\Http\Requests\Api\V1\Professionals\UpdateProfessionalRequest;
use App\Http\Resources\Api\V1\ProfessionalResource;
use App\Models\Professional;
use App\Models\ServiceCategory;
use App\Models\Specialization;
use App\Services\ManagedMediaService;
use App\Support\Professionals\IbanFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfessionalController extends Controller
{
    public function __construct(
        private readonly ManagedMediaService $media,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $professionals = Professional::query()
            ->with($this->relations())
            ->orderBy('full_name')
            ->get();

        return ProfessionalResource::collection($professionals);
    }

    public function store(StoreProfessionalRequest $request): ProfessionalResource
    {
        $professional = DB::transaction(function () use ($request): Professional {
            $payload = $request->validated();
            $specializationIds = $this->extractSpecializationIds($payload);
            $areaNames = $this->resolveAreaNamesFromSpecializations($specializationIds, $payload);

            $professional = Professional::query()->create($this->attributes($payload, $areaNames));
            $avatarPath = $this->storeAvatar($request, $professional->id);
            if ($avatarPath !== null) {
                $professional->forceFill(['avatar_path' => $avatarPath])->save();
            }

            $this->syncAreas($professional, $areaNames);
            $this->syncSpecializations($professional, $specializationIds);
            $this->syncMasterCollections($professional, $payload);
            $professional->forceFill(['area_name' => $areaNames[0] ?? null])->save();

            return $professional->load($this->relations());
        });

        return new ProfessionalResource($professional);
    }

    public function show(Professional $professional): ProfessionalResource
    {
        return new ProfessionalResource($professional->load($this->relations()));
    }

    public function update(UpdateProfessionalRequest $request, Professional $professional): ProfessionalResource
    {
        $professional = DB::transaction(function () use ($request, $professional): Professional {
            $payload = $request->validated();
            $specializationIds = $this->extractSpecializationIds($payload);
            $areaNames = $this->resolveAreaNamesFromSpecializations($specializationIds, $payload);

            $professional->fill($this->attributes($payload, $areaNames));
            $professional->save();

            $avatarPath = $this->storeAvatar($request, $professional->id);
            if ($avatarPath !== null) {
                $oldAvatarPath = $professional->avatar_path;
                $professional->forceFill(['avatar_path' => $avatarPath])->save();
                $this->media->deleteManagedFile($oldAvatarPath, [
                    "professionals/{$professional->id}",
                    "professional-avatars/{$professional->id}",
                ]);
            }

            $this->syncAreas($professional, $areaNames);
            $this->syncSpecializations($professional, $specializationIds);
            $this->syncMasterCollections($professional, $payload);
            $professional->forceFill(['area_name' => $areaNames[0] ?? null])->save();

            return $professional->refresh()->load($this->relations());
        });

        return new ProfessionalResource($professional);
    }

    public function destroy(Professional $professional): Response|JsonResponse
    {
        $dependencies = collect([
            'performance_records' => $professional->performanceRecords()->count(),
            'appointments' => DB::table('appointments')->where('professional_id', $professional->id)->count(),
        ])->filter(fn (int $count): bool => $count > 0)->all();

        if ($dependencies !== []) {
            return response()->json([
                'message' => 'Il professionista è referenziato e non può essere eliminato.',
                'dependencies' => $dependencies,
            ], Response::HTTP_CONFLICT);
        }

        $avatarPath = $professional->avatar_path;
        DB::transaction(function () use ($professional): void {
            $professional->publicProfile?->delete();
            $professional->delete();
        });
        $this->media->deleteManagedFile($avatarPath, [
            "professionals/{$professional->id}",
            "professional-avatars/{$professional->id}",
        ]);

        return response()->noContent();
    }

    /**
     * @param  array<int, string>  $areaNames
     */
    private function attributes(array $payload, array $areaNames): array
    {
        $subjectType = ProfessionalSubjectType::from((string) $payload['subject_type']);
        $firstName = isset($payload['first_name']) ? trim((string) $payload['first_name']) : null;
        $lastName = isset($payload['last_name']) ? trim((string) $payload['last_name']) : null;
        $companyName = isset($payload['company_name']) ? trim((string) $payload['company_name']) : null;

        return [
            'subject_type' => $subjectType->value,
            'gender' => $subjectType === ProfessionalSubjectType::Individual
                ? ($payload['gender'] ?? ProfessionalGender::Unspecified->value)
                : ProfessionalGender::Unspecified->value,
            'honorific_prefix' => $this->nullableTrimmed($payload['honorific_prefix'] ?? null),
            'first_name' => $subjectType === ProfessionalSubjectType::Individual ? $firstName : null,
            'last_name' => $subjectType === ProfessionalSubjectType::Individual ? $lastName : null,
            'company_name' => $subjectType === ProfessionalSubjectType::Company ? $companyName : null,
            'full_name' => $subjectType === ProfessionalSubjectType::Company
                ? (string) $companyName
                : trim(((string) $lastName).' '.((string) $firstName)),
            'birth_date' => $payload['birth_date'] ?? null,
            'birth_place' => $this->nullableTrimmed($payload['birth_place'] ?? null),
            'area_name' => $areaNames[0] ?? $this->nullableTrimmed($payload['area_name'] ?? null),
            'email' => $payload['email'] ?? null,
            'iban' => IbanFormatter::normalize($payload['iban'] ?? null),
            'is_active' => $payload['is_active'] ?? true,
            'notes' => $payload['notes'] ?? null,
        ];
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
     * @return array<int, string>
     */
    private function resolveAreaNamesFromSpecializations(array $specializationIds, array $payload): array
    {
        $names = Specialization::query()
            ->whereIn('id', $specializationIds)
            ->get()
            ->sortBy(fn (Specialization $specialization) => array_search($specialization->id, $specializationIds, true))
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => trim((string) $name))
            ->unique()
            ->values();

        if ($names->isNotEmpty()) {
            return $names->all();
        }

        $fallback = collect($payload['area_names'] ?? [])
            ->map(fn ($name) => $this->nullableTrimmed($name))
            ->filter()
            ->values();

        $single = $this->nullableTrimmed($payload['area_name'] ?? null);
        if ($single !== null && ! $fallback->contains($single)) {
            $fallback->prepend($single);
        }

        return $fallback->all();
    }

    /**
     * @param  array<int, string>  $areaNames
     */
    private function syncAreas(Professional $professional, array $areaNames): void
    {
        $areaSyncData = collect($areaNames)
            ->values()
            ->map(function (string $areaName, int $index): array {
                $slug = Str::slug($areaName);
                $categoryId = (int) ServiceCategory::query()->firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $areaName, 'is_active' => true],
                )->id;

                return [
                    'id' => $categoryId,
                    'sort_order' => $index,
                ];
            })
            ->unique('id')
            ->values()
            ->mapWithKeys(fn (array $entry): array => [
                $entry['id'] => ['sort_order' => $entry['sort_order']],
            ])
            ->all();

        $professional->areas()->sync($areaSyncData);
    }

    /**
     * @param  array<int, int>  $specializationIds
     */
    private function syncSpecializations(Professional $professional, array $specializationIds): void
    {
        $specializationSync = collect($specializationIds)
            ->values()
            ->mapWithKeys(fn (int $id, int $index): array => [
                $id => [
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                ],
            ])
            ->all();

        $professional->specializations()->sync($specializationSync);
    }

    private function storeAvatar(StoreProfessionalRequest|UpdateProfessionalRequest $request, int $professionalId): ?string
    {
        $file = $request->file('avatar');
        if (! $file) {
            return null;
        }

        return $file->store("professional-avatars/{$professionalId}", 'public');
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function relations(): array
    {
        return [
            'areas',
            'specializations',
            'publicProfile',
            'degrees',
            'academicSpecializations',
            'boardRegistrations',
            'careerExperiences',
        ];
    }

    private function syncMasterCollections(Professional $professional, array $payload): void
    {
        $definitions = [
            'degrees' => [$professional->degrees(), ['title', 'awarded_on']],
            'academic_specializations' => [$professional->academicSpecializations(), ['title', 'awarded_on']],
            'board_registrations' => [$professional->boardRegistrations(), ['board_name', 'registration_number', 'registered_on']],
            'career_experiences' => [$professional->careerExperiences(), ['year_from', 'year_to', 'is_current', 'title', 'organization', 'description']],
        ];

        foreach ($definitions as $key => [$relation, $fields]) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $existing = $relation->get()->keyBy('id');
            $keptIds = [];

            foreach (array_values($payload[$key] ?? []) as $index => $item) {
                $attributes = collect($item)->only($fields)->all();
                $attributes['sort_order'] = $index;
                if (in_array('is_current', $fields, true) && ($attributes['is_current'] ?? false)) {
                    $attributes['year_to'] = null;
                }

                $id = isset($item['id']) ? (int) $item['id'] : null;
                if ($id !== null && ! $existing->has($id)) {
                    throw ValidationException::withMessages(["{$key}.{$index}.id" => 'Il record non appartiene al professionista.']);
                }

                $model = $id !== null ? $existing->get($id) : $relation->make();
                $model->fill($attributes);
                $model->save();
                $keptIds[] = $model->id;
            }

            $relation->whereNotIn('id', $keptIds ?: [0])->delete();
        }
    }
}
