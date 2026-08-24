<?php

namespace App\Services;

use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Support\MedicalAreas\MedicalAreaSectionDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicalAreaContentService
{
    public function initializeSections(SpecializationWebProfile $profile): void
    {
        foreach (MedicalAreaSectionDefinition::keys() as $order => $key) {
            $profile->sections()->firstOrCreate(
                ['key' => $key],
                [
                    'title' => MedicalAreaSectionDefinition::DEFINITIONS[$key],
                    'sort_order' => $order,
                    'is_active' => true,
                ]
            );
        }
    }

    public function upsert(Specialization $specialization, array $payload): SpecializationWebProfile
    {
        return DB::transaction(function () use ($specialization, $payload): SpecializationWebProfile {
            $sections = $payload['sections'] ?? null;
            $faqs = $payload['faqs'] ?? null;
            unset($payload['sections'], $payload['faqs']);

            $profile = $specialization->webProfile()->firstOrNew();
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

    public function syncSections(SpecializationWebProfile $profile, array $sections): void
    {
        $existing = $profile->sections()->whereIn('key', MedicalAreaSectionDefinition::keys())->get()->keyBy('key');

        foreach (array_values($sections) as $order => $payload) {
            $key = $payload['key'];
            $section = $existing->get($key) ?? $profile->sections()->make(['key' => $key]);
            $section->fill([
                'title' => $key === 'hero'
                    ? MedicalAreaSectionDefinition::DEFINITIONS['hero']
                    : ($payload['title'] ?? MedicalAreaSectionDefinition::DEFINITIONS[$key]),
                'content' => $key === 'hero' ? null : ($payload['intro'] ?? null),
                'extra_json' => $this->sectionData($key, $payload['data'] ?? []),
                'sort_order' => $order,
                'is_active' => (bool) $payload['is_active'],
            ]);
            $section->save();
        }
    }

    public function syncFaqs(SpecializationWebProfile $profile, array $items): void
    {
        $existing = $profile->faqs()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($items) as $order => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages([
                    "faqs.{$order}.id" => 'La FAQ non appartiene a questa Area medica.',
                ]);
            }

            $faq = $id ? $existing->get($id) : $profile->faqs()->make();
            $faq->fill([
                'question' => trim($item['question']),
                'answer' => trim($item['answer']),
                'sort_order' => $order,
                'is_active' => (bool) ($item['is_active'] ?? true),
                'is_structured_data' => (bool) ($item['is_structured_data'] ?? true),
            ]);
            $faq->save();
            $kept[] = $faq->id;
        }

        $profile->faqs()->whereNotIn('id', $kept ?: [0])->delete();
    }

    private function sectionData(string $key, array $data): ?array
    {
        return match ($key) {
            'scope' => [
                'items' => array_values($data['items'] ?? []),
                'bottom_note' => $data['bottom_note'] ?? null,
            ],
            'when_useful' => [
                'items' => array_values($data['items'] ?? []),
                'alert_enabled' => (bool) ($data['alert_enabled'] ?? false),
                'alert_title' => $data['alert_title'] ?? null,
                'alert_text' => $data['alert_text'] ?? null,
            ],
            'visit_process' => [
                'steps' => array_values($data['steps'] ?? []),
                'appointment_preparation_enabled' => (bool) ($data['appointment_preparation_enabled'] ?? false),
                'appointment_preparation_label' => $data['appointment_preparation_label'] ?? null,
                'appointment_preparation_items' => array_values($data['appointment_preparation_items'] ?? []),
            ],
            default => null,
        };
    }
}
