<?php

namespace App\Services;

use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Section;
use App\Support\Pages\PageSectionRegistry;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PageContentService
{
    /** @param array<string, mixed> $payload */
    public function sync(Page $page, array $payload): void
    {
        $this->syncSections($page, $payload);
        $this->syncFaqs($page, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function syncSections(Page $page, array $payload): void
    {
        if (! array_key_exists('sections', $payload) && ! array_key_exists('removed_section_keys', $payload)) {
            return;
        }

        $removedKeys = array_values(array_unique($payload['removed_section_keys'] ?? []));
        if ($removedKeys !== []) {
            $page->sections()->whereIn('key', $removedKeys)->delete();
        }

        if (! array_key_exists('sections', $payload)) {
            return;
        }

        /** @var array<string, Section> $existing */
        $existing = $page->sections()->get()->keyBy('key')->all();

        foreach (array_values($payload['sections'] ?? []) as $index => $sectionPayload) {
            $key = (string) $sectionPayload['key'];
            $section = $existing[$key] ?? null;

            if ($section === null) {
                if (! PageSectionRegistry::canCreate((string) $page->internal_key, $key)) {
                    throw ValidationException::withMessages([
                        "sections.{$index}.key" => 'La sezione non è consentita per questa pagina.',
                    ]);
                }

                $section = $page->sections()->make(['key' => $key]);
            }

            $section->fill(Arr::only($sectionPayload, [
                'title',
                'subtitle',
                'content',
                'extra_json',
                'sort_order',
                'is_active',
            ]));

            if ($section->isDirty()) {
                $section->save();
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function syncFaqs(Page $page, array $payload): void
    {
        if (! array_key_exists('faqs', $payload) && ! array_key_exists('removed_faq_ids', $payload)) {
            return;
        }

        $removedIds = array_values(array_unique(array_map('intval', $payload['removed_faq_ids'] ?? [])));
        if ($removedIds !== []) {
            $page->faqs()->whereIn('id', $removedIds)->delete();
        }

        if (! array_key_exists('faqs', $payload)) {
            return;
        }

        /** @var array<int, FaqItem> $existing */
        $existing = $page->faqs()->get()->keyBy('id')->all();

        foreach (array_values($payload['faqs'] ?? []) as $index => $faqPayload) {
            $id = isset($faqPayload['id']) ? (int) $faqPayload['id'] : null;
            $faq = $id !== null ? ($existing[$id] ?? null) : null;

            if ($id !== null && $faq === null) {
                throw ValidationException::withMessages([
                    "faqs.{$index}.id" => 'La FAQ non appartiene a questa pagina.',
                ]);
            }

            $faq ??= $page->faqs()->make();
            $faq->fill([
                'question' => trim((string) $faqPayload['question']),
                'answer' => trim((string) $faqPayload['answer']),
                'sort_order' => $faqPayload['sort_order'] ?? $index,
                'is_active' => $faqPayload['is_active'] ?? true,
                'is_structured_data' => $faqPayload['is_structured_data'] ?? true,
            ]);

            if ($faq->isDirty()) {
                $faq->save();
            }
        }
    }
}
