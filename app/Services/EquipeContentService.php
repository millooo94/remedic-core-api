<?php

namespace App\Services;

use App\Models\ProfessionalPublicProfile;
use App\Support\Professionals\EquipeSectionDefinition;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipeContentService
{
    public function initializeSections(ProfessionalPublicProfile $profile): void
    {
        foreach (array_keys(EquipeSectionDefinition::DEFINITIONS) as $order => $key) {
            $profile->sections()->firstOrCreate(
                ['key' => $key],
                [
                    'title' => EquipeSectionDefinition::DEFINITIONS[$key],
                    'sort_order' => $order,
                    'is_active' => true,
                ]
            );
        }
    }

    public function updateSections(ProfessionalPublicProfile $profile, array $sections): void
    {
        DB::transaction(function () use ($profile, $sections): void {
            $this->initializeSections($profile);

            foreach ($sections as $index => $section) {
                $updates = [
                    'sort_order' => $index,
                    'is_active' => (bool) $section['is_active'],
                ];
                if ($section['key'] === 'services') {
                    $updates['title'] = $section['title'] ?? EquipeSectionDefinition::DEFINITIONS['services'];
                    $updates['content'] = $section['intro'] ?? null;
                }
                $profile->sections()
                    ->where('key', $section['key'])
                    ->update($updates);
            }
        });
    }

    public function syncTypedContent(ProfessionalPublicProfile $profile, array $payload): void
    {
        if (array_key_exists('competencies', $payload)) {
            $this->syncCollection(
                $profile->competencies(),
                $payload['competencies'] ?? [],
                ['title', 'description', 'icon_key', 'is_active'],
                'competencies'
            );
        }

        if (array_key_exists('approach_principles', $payload)) {
            $this->syncCollection(
                $profile->approachPrinciples(),
                $payload['approach_principles'] ?? [],
                ['label', 'icon_key', 'is_active'],
                'approach_principles'
            );
        }

        if (array_key_exists('scientific_activities', $payload)) {
            $this->syncCollection(
                $profile->scientificActivities(),
                $payload['scientific_activities'] ?? [],
                ['contribution_type', 'occurred_on', 'year', 'title', 'source', 'short_description', 'url', 'is_active'],
                'scientific_activities'
            );
        }

        if (array_key_exists('hero_competency_ids', $payload)) {
            $ids = collect($payload['hero_competency_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
            $ownedIds = $profile->competencies()->whereIn('id', $ids)->pluck('id');
            if ($ownedIds->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'hero_competency_ids' => 'Le competenze Hero devono appartenere al profilo Equipe.',
                ]);
            }

            $profile->heroCompetencies()->sync(
                $ids->mapWithKeys(fn (int $id, int $index) => [$id => ['sort_order' => $index]])->all()
            );
        }
    }

    private function syncCollection(HasMany $relation, array $items, array $fields, string $key): void
    {
        $existing = $relation->get()->keyBy('id');
        $keptIds = [];

        foreach (array_values($items) as $index => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["{$key}.{$index}.id" => 'Il record non appartiene al profilo Equipe.']);
            }

            $model = $id !== null ? $existing->get($id) : $relation->make();
            $model->fill(collect($item)->only($fields)->all() + ['sort_order' => $index]);
            $model->save();
            $keptIds[] = $model->id;
        }

        $relation->whereNotIn('id', $keptIds ?: [0])->delete();
    }
}
