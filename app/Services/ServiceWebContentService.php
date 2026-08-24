<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Support\Services\ServiceSectionDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceWebContentService
{
    public function initializeSections(ServiceWebProfile $profile): void
    {
        foreach (ServiceSectionDefinition::keys() as $order => $key) {
            $profile->sections()->firstOrCreate(
                ['key' => $key],
                [
                    'title' => ServiceSectionDefinition::DEFINITIONS[$key],
                    'sort_order' => $order,
                    'is_active' => true,
                ]
            );
        }
    }

    public function upsert(Service $service, array $payload): ServiceWebProfile
    {
        return DB::transaction(function () use ($service, $payload): ServiceWebProfile {
            $sections = $payload['sections'] ?? null;
            $faqs = $payload['faqs'] ?? null;
            unset($payload['sections'], $payload['faqs']);

            $profile = $service->webProfile()->firstOrNew();
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

    public function syncSections(ServiceWebProfile $profile, array $sections): void
    {
        $existing = $profile->sections()
            ->whereIn('key', ServiceSectionDefinition::keys())
            ->get()
            ->keyBy('key');

        foreach (array_values($sections) as $order => $payload) {
            $key = $payload['key'];
            $section = $existing->get($key) ?? $profile->sections()->make(['key' => $key]);
            $section->fill([
                'title' => $key === 'hero'
                    ? ServiceSectionDefinition::DEFINITIONS['hero']
                    : ($payload['title'] ?? ServiceSectionDefinition::DEFINITIONS[$key]),
                'content' => $key === 'hero' ? null : ($payload['intro'] ?? null),
                'extra_json' => $this->sectionData($key, $payload['data'] ?? []),
                'sort_order' => $order,
                'is_active' => (bool) $payload['is_active'],
            ]);
            $section->save();
        }
    }

    public function syncFaqs(ServiceWebProfile $profile, array $items): void
    {
        $existing = $profile->faqs()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($items) as $order => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages([
                    "faqs.{$order}.id" => 'La FAQ non appartiene a questa Prestazione.',
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
            'what_is' => [
                'items' => array_values($data['items'] ?? []),
                'bottom_note' => $data['bottom_note'] ?? null,
            ],
            'when_to_request' => [
                'groups' => array_values($data['groups'] ?? []),
            ],
            'procedure' => [
                'steps' => array_values($data['steps'] ?? []),
                'additional_info_enabled' => (bool) ($data['additional_info_enabled'] ?? false),
                'additional_info_title' => $data['additional_info_title'] ?? null,
                'additional_info_text' => $data['additional_info_text'] ?? null,
                'additional_info_items' => array_values($data['additional_info_items'] ?? []),
            ],
            'preparation' => [
                'items' => array_values($data['items'] ?? []),
                'info_box_enabled' => (bool) ($data['info_box_enabled'] ?? false),
                'info_box_text' => $data['info_box_text'] ?? null,
            ],
            default => null,
        };
    }
}
