<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProfessionalSubjectType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Professionals\StoreProfessionalRequest;
use App\Http\Requests\Api\V1\Professionals\UpdateProfessionalRequest;
use App\Http\Resources\Api\V1\ProfessionalResource;
use App\Models\Professional;
use App\Models\ServiceCategory;
use App\Models\Specialization;
use App\Support\Professionals\IbanFormatter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfessionalController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $professionals = Professional::query()
            ->with(['areas', 'specializations'])
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
            $professional->forceFill(['area_name' => $areaNames[0] ?? null])->save();

            return $professional->load(['areas', 'specializations']);
        });

        return new ProfessionalResource($professional);
    }

    public function show(Professional $professional): ProfessionalResource
    {
        return new ProfessionalResource($professional->load(['areas', 'specializations']));
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
                if ($professional->avatar_path) {
                    Storage::disk('public')->delete($professional->avatar_path);
                }

                $professional->forceFill(['avatar_path' => $avatarPath])->save();
            }

            $this->syncAreas($professional, $areaNames);
            $this->syncSpecializations($professional, $specializationIds);
            $professional->forceFill(['area_name' => $areaNames[0] ?? null])->save();

            return $professional->refresh()->load(['areas', 'specializations']);
        });

        return new ProfessionalResource($professional);
    }

    public function destroy(Professional $professional): Response
    {
        if ($professional->avatar_path) {
            Storage::disk('public')->delete($professional->avatar_path);
        }

        $professional->delete();

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
            'first_name' => $subjectType === ProfessionalSubjectType::Individual ? $firstName : null,
            'last_name' => $subjectType === ProfessionalSubjectType::Individual ? $lastName : null,
            'company_name' => $subjectType === ProfessionalSubjectType::Company ? $companyName : null,
            'full_name' => $subjectType === ProfessionalSubjectType::Company
                ? (string) $companyName
                : trim(((string) $lastName).' '.((string) $firstName)),
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
}
