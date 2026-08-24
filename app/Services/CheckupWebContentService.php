<?php

namespace App\Services;

use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use App\Support\Checkups\CheckupSectionDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckupWebContentService
{
    public function initializeSections(CheckupWebProfile $profile): void
    {
        foreach (CheckupSectionDefinition::keys() as $order => $key) {
            $profile->sections()->firstOrCreate(['key' => $key], [
                'title' => CheckupSectionDefinition::DEFINITIONS[$key],
                'sort_order' => $order,
                'is_active' => true,
            ]);
        }
    }

    public function upsert(Checkup $checkup, array $payload): CheckupWebProfile
    {
        return DB::transaction(function () use ($checkup, $payload): CheckupWebProfile {
            $sections = $payload['sections'] ?? null;
            $faqs = $payload['faqs'] ?? null;
            unset($payload['sections'], $payload['faqs']);

            $profile = $checkup->webProfile()->firstOrNew();
            $profile->fill($payload);
            $profile->save();
            $this->initializeSections($profile);

            if (is_array($sections)) {
                $this->syncSections($profile, $sections);
            }
            if (is_array($faqs)) {
                $this->syncFaqs($profile, $faqs);
            }

            return $profile;
        });
    }

    private function syncSections(CheckupWebProfile $profile, array $sections): void
    {
        $existing = $profile->sections()->whereIn('key', CheckupSectionDefinition::keys())->get()->keyBy('key');

        foreach (array_values($sections) as $order => $payload) {
            $key = $payload['key'];
            $section = $existing->get($key) ?? $profile->sections()->make(['key' => $key]);
            $section->fill([
                'title' => $key === 'hero' ? CheckupSectionDefinition::DEFINITIONS['hero'] : ($payload['title'] ?? CheckupSectionDefinition::DEFINITIONS[$key]),
                'content' => $key === 'hero' ? null : ($payload['intro'] ?? null),
                'extra_json' => $this->sectionData($key, $payload['data'] ?? []),
                'sort_order' => $order,
                'is_active' => (bool) $payload['is_active'],
            ])->save();
        }
    }

    private function syncFaqs(CheckupWebProfile $profile, array $items): void
    {
        $existing = $profile->faqs()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($items) as $order => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["faqs.{$order}.id" => 'La FAQ non appartiene a questo Check-up.']);
            }

            $faq = $id ? $existing->get($id) : $profile->faqs()->make();
            $faq->fill([
                'question' => trim($item['question']),
                'answer' => trim($item['answer']),
                'sort_order' => $order,
                'is_active' => (bool) ($item['is_active'] ?? true),
                'is_structured_data' => (bool) ($item['is_structured_data'] ?? true),
            ])->save();
            $kept[] = $faq->id;
        }

        $profile->faqs()->whereNotIn('id', $kept ?: [0])->delete();
    }

    private function sectionData(string $key, array $data): ?array
    {
        return match ($key) {
            'what_is' => ['items' => array_values($data['items'] ?? [])],
            'target' => ['groups' => array_values($data['groups'] ?? [])],
            'procedure' => ['steps' => array_values($data['steps'] ?? [])],
            'preparation' => ['items' => array_values($data['items'] ?? [])],
            default => null,
        };
    }
}
