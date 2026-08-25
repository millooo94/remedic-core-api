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
        $this->initializeMissingSections($page);
        $this->syncSections($page, $payload);
        $this->syncFaqs($page, $payload);
    }

    public function initializeMissingSections(Page $page): void
    {
        foreach (PageSectionRegistry::missingDefaults($page) as $section) {
            $page->sections()->create($section);
        }
    }

    /** @param array<string, mixed> $payload */
    private function syncSections(Page $page, array $payload): void
    {
        if (! array_key_exists('sections', $payload) && ! array_key_exists('removed_section_keys', $payload)) {
            return;
        }

        $removedKeys = array_values(array_unique($payload['removed_section_keys'] ?? []));
        if (PageSectionRegistry::hasDefinitionsFor((string) $page->internal_key)
            && array_intersect($removedKeys, array_keys(PageSectionRegistry::definitions((string) $page->internal_key))) !== []) {
            throw ValidationException::withMessages([
                'removed_section_keys' => 'Le sezioni tipizzate richieste dalla pagina non possono essere rimosse.',
            ]);
        }
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

            $attributes = Arr::only($sectionPayload, [
                'title', 'subtitle', 'content', 'extra_json', 'sort_order', 'is_active',
            ]);

            if (PageSectionRegistry::hasDefinitionsFor((string) $page->internal_key)) {
                $attributes = $this->typedSectionAttributes($page, $section, $sectionPayload, $index);
            }

            $section->fill($attributes);

            if ($section->isDirty()) {
                $section->save();
            }
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function typedSectionAttributes(Page $page, Section $section, array $payload, int $index): array
    {
        $definition = PageSectionRegistry::definition((string) $page->internal_key, $section->key);
        if ($definition === null) {
            // Historical unknown keys are retained untouched and never become a
            // writable generic editor on a closed typed Page.
            throw ValidationException::withMessages([
                "sections.{$index}.key" => 'La sezione non è registrata per questa pagina tipizzata.',
            ]);
        }

        if (array_key_exists('extra_json', $payload)) {
            throw ValidationException::withMessages([
                "sections.{$index}.extra_json" => 'I dati della sezione sono gestiti dall’editor tipizzato.',
            ]);
        }

        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw ValidationException::withMessages([
                "sections.{$index}.data" => 'I dati della sezione non sono validi.',
            ]);
        }

        $allowedData = $this->allowedData((string) $page->internal_key, $section->key);

        $unknownData = array_diff(array_keys($data), $allowedData);
        if ($unknownData !== []) {
            throw ValidationException::withMessages([
                "sections.{$index}.data" => 'Il payload contiene campi non consentiti.',
            ]);
        }

        $extra = $section->extra_json ?? [];
        foreach (['eyebrow', 'link_label', 'image_alt', 'disclaimer', 'callout_eyebrow', 'callout_body', 'subheading', 'privacy_text'] as $key) {
            if (array_key_exists($key, $data)) {
                $extra[$key] = $data[$key] === null ? null : trim((string) $data[$key]);
            }
        }

        if (isset($definition['target_internal_key'])) {
            $extra['target_internal_key'] = $definition['target_internal_key'];
        }
        if (isset($definition['actions'])) {
            $extra['actions'] = $definition['actions'];
        }

        if (array_key_exists('items', $data)) {
            $extra['items'] = match ((string) $page->internal_key) {
                PageSectionRegistry::CONVENTIONS_NETWORK_INTERNAL_KEY => $section->key === 'access_process'
                    ? $this->normalizeConventionAccessProcessItems($data['items'], $index)
                    : $this->normalizeItems($section->key, $data['items'], $index),
                PageSectionRegistry::CAREERS_INTERNAL_KEY => $this->normalizeCareerItems($section->key, $data['items'], $index),
                default => $this->normalizeItems($section->key, $data['items'], $index),
            };
        }
        if (array_key_exists('testimonials', $data)) {
            $extra['testimonials'] = $this->normalizeTestimonials($data['testimonials'], $index);
        }
        if (array_key_exists('values', $data)) {
            $extra['values'] = $this->normalizeProtocolValues($data['values'], $index);
        }
        if (array_key_exists('pillars', $data)) {
            $extra['pillars'] = $this->normalizeProtocolPillars($data['pillars'], $index);
        }

        return [
            'title' => array_key_exists('title', $payload) ? $payload['title'] : $section->title,
            'content' => array_key_exists('intro', $data) ? $data['intro'] : (array_key_exists('body', $data) ? $data['body'] : $section->content),
            'extra_json' => $extra,
            'sort_order' => $payload['sort_order'] ?? $section->sort_order,
            'is_active' => $payload['is_active'] ?? $section->is_active,
        ];
    }

    /** @return list<string> */
    private function allowedData(string $internalKey, string $sectionKey): array
    {
        if ($internalKey === PageSectionRegistry::WHY_CHOOSE_US_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'model_overview' => ['eyebrow', 'body', 'items'],
                'three_reasons' => ['body', 'items'],
                'integrated_workflow', 'continuity' => ['body', 'image_alt'],
                'patient_experiences' => ['eyebrow', 'body', 'disclaimer', 'testimonials'],
                'plus_health_protocol_cta' => ['body', 'link_label'],
                'orientation_cta' => ['body'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'promise' => ['eyebrow', 'body', 'values'],
                'four_pillars' => ['eyebrow', 'body', 'pillars'],
                'care_path_overview' => ['body', 'items'],
                'active_listening', 'personalized_care_plan', 'clinical_technology' => ['body', 'image_alt'],
                'patient_education' => ['body', 'callout_eyebrow', 'callout_body'],
                'person_first' => ['eyebrow', 'body', 'image_alt', 'items'],
                'method_statement' => ['eyebrow', 'body'],
                'orientation_cta' => ['body'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::CONTACT_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'location_and_contacts' => ['intro'],
                'orientation_cta' => ['body'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::CONVENTIONS_NETWORK_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'access_process' => ['intro', 'items'],
                'conventions_catalog' => ['intro'],
                'contact_cta' => ['body'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::CAREERS_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'professional_profiles' => ['intro', 'subheading', 'items'],
                'what_we_look_for' => ['intro', 'items'],
                'application' => ['body', 'privacy_text'],
                default => [],
            };
        }

        return match ($sectionKey) {
            'hero' => ['eyebrow', 'body', 'image_alt'],
            'intro' => ['body'],
            'coordinated_care', 'continuity' => ['body', 'image_alt'],
            'why_remedic', 'plus_health_protocol' => ['eyebrow', 'body', 'link_label', 'image_alt'],
            'orientation_cta' => ['body'],
            default => [],
        };
    }

    /** @return list<array{semantic_key: string, title: string, description: string, icon_key: string}> */
    private function normalizeConventionAccessProcessItems(mixed $items, int $index): array
    {
        $expected = ['direct_booking', 'practice_management', 'agreement_conditions'];
        $icons = ['calendar', 'clipboard', 'info'];
        if (! is_array($items) || array_values(array_map(fn ($item) => is_array($item) ? $item['semantic_key'] ?? null : null, $items)) !== $expected) {
            throw ValidationException::withMessages(["sections.{$index}.data.items" => 'I tre passaggi hanno chiavi e ordine fissi.']);
        }

        return array_map(function (array $item, int $itemIndex) use ($expected, $icons, $index): array {
            if (array_diff(array_keys($item), ['semantic_key', 'title', 'description']) !== [] || ! isset($item['title'], $item['description'])) {
                throw ValidationException::withMessages(["sections.{$index}.data.items.{$itemIndex}" => 'Il passaggio contiene dati non consentiti.']);
            }

            return [
                'semantic_key' => $expected[$itemIndex],
                'title' => trim((string) $item['title']),
                'description' => trim((string) $item['description']),
                'icon_key' => $icons[$itemIndex],
            ];
        }, array_values($items), array_keys(array_values($items)));
    }

    /** @return list<array<string, string>> */
    private function normalizeItems(string $sectionKey, mixed $items, int $index): array
    {
        if (! is_array($items)) {
            throw ValidationException::withMessages(["sections.{$index}.data.items" => 'Gli elementi non sono validi.']);
        }

        if ($sectionKey === 'care_path_overview') {
            return $this->normalizeFixedCarePathItems($items, $index);
        }

        if ($sectionKey === 'person_first') {
            if (count($items) !== 3) {
                throw ValidationException::withMessages(["sections.{$index}.data.items" => 'La sezione prevede esattamente tre punti ordinabili.']);
            }
        }

        $hasIcon = $sectionKey === 'three_reasons';
        $allowedIcons = ['network', 'microscope', 'heart'];
        $normalized = [];
        foreach (array_values($items) as $itemIndex => $item) {
            if (! is_array($item) || ! isset($item['title'], $item['description'])) {
                throw ValidationException::withMessages(["sections.{$index}.data.items.{$itemIndex}" => 'Titolo e descrizione sono obbligatori.']);
            }
            if ($hasIcon && (! isset($item['icon_key']) || ! in_array($item['icon_key'], $allowedIcons, true))) {
                throw ValidationException::withMessages(["sections.{$index}.data.items.{$itemIndex}.icon_key" => 'L’icona selezionata non è consentita.']);
            }
            $normalized[] = array_filter([
                'icon_key' => $hasIcon ? (string) $item['icon_key'] : null,
                'title' => trim((string) $item['title']),
                'description' => trim((string) $item['description']),
            ], fn ($value) => $value !== null);
        }

        return $normalized;
    }

    /** @return list<array{semantic_key: string, title: string, description: string}> */
    private function normalizeCareerItems(string $sectionKey, mixed $items, int $index): array
    {
        $expected = match ($sectionKey) {
            'professional_profiles' => ['specialist_doctors', 'healthcare_professionals', 'collaborations', 'organizational_area'],
            'what_we_look_for' => ['person_care', 'quality', 'clarity', 'multidisciplinary_collaboration', 'reliability', 'continuity'],
            default => [],
        };
        if ($expected === [] || ! is_array($items) || array_values(array_map(fn ($item) => is_array($item) ? $item['semantic_key'] ?? null : null, $items)) !== $expected) {
            throw ValidationException::withMessages(["sections.{$index}.data.items" => 'Le card hanno chiavi e ordine fissi.']);
        }

        return array_map(function (array $item, int $itemIndex) use ($expected, $index): array {
            if (array_diff(array_keys($item), ['semantic_key', 'title', 'description']) !== [] || ! isset($item['title'], $item['description'])) {
                throw ValidationException::withMessages(["sections.{$index}.data.items.{$itemIndex}" => 'La card contiene dati non consentiti.']);
            }

            return [
                'semantic_key' => $expected[$itemIndex],
                'title' => trim((string) $item['title']),
                'description' => trim((string) $item['description']),
            ];
        }, array_values($items), array_keys(array_values($items)));
    }

    /** @return list<array{semantic_key: string, label: string, description: string}> */
    private function normalizeProtocolValues(mixed $values, int $index): array
    {
        return $this->normalizeFixedSemanticRows($values, $index, 'values', ['rapidity', 'professionalism', 'accessibility', 'humanity'], ['label', 'description']);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeProtocolPillars(mixed $pillars, int $index): array
    {
        $expected = ['rapidity', 'professionalism', 'accessibility', 'humanity'];
        $labels = ['Rapidità', 'Professionalità', 'Accessibilità', 'Umanità'];
        if (! is_array($pillars) || array_values(array_map(fn ($pillar) => is_array($pillar) ? $pillar['semantic_key'] ?? null : null, $pillars)) !== $expected) {
            throw ValidationException::withMessages(["sections.{$index}.data.pillars" => 'I quattro pannelli hanno chiavi e ordine fissi.']);
        }

        $normalized = [];
        foreach (array_values($pillars) as $pillarIndex => $pillar) {
            $unknown = array_diff(array_keys($pillar), ['semantic_key', 'label', 'detail_eyebrow', 'detail_title', 'detail_description', 'bullets']);
            if ($unknown !== []) {
                throw ValidationException::withMessages(["sections.{$index}.data.pillars.{$pillarIndex}" => 'Il pannello contiene campi non consentiti.']);
            }
            if (! isset($pillar['label'], $pillar['detail_description']) || $pillar['label'] !== $labels[$pillarIndex] || ! is_array($pillar['bullets'] ?? null)) {
                throw ValidationException::withMessages(["sections.{$index}.data.pillars.{$pillarIndex}" => 'Label, descrizione e bullet list sono obbligatorie.']);
            }
            $normalized[] = [
                'semantic_key' => $expected[$pillarIndex],
                'label' => $labels[$pillarIndex],
                'detail_eyebrow' => isset($pillar['detail_eyebrow']) ? trim((string) $pillar['detail_eyebrow']) : null,
                'detail_title' => isset($pillar['detail_title']) ? trim((string) $pillar['detail_title']) : null,
                'detail_description' => trim((string) $pillar['detail_description']),
                'bullets' => array_values(array_map(fn ($bullet) => trim((string) $bullet), $pillar['bullets'])),
            ];
        }

        return $normalized;
    }

    /** @return list<array<string, string>> */
    private function normalizeFixedCarePathItems(mixed $items, int $index): array
    {
        $expected = ['active_listening', 'personalized_care_plan', 'clinical_technology', 'patient_education'];
        $allowedIcons = ['message', 'clipboard', 'microscope', 'info'];
        if (! is_array($items) || array_values(array_map(fn ($item) => is_array($item) ? $item['semantic_key'] ?? null : null, $items)) !== $expected) {
            throw ValidationException::withMessages(["sections.{$index}.data.items" => 'I quattro passaggi hanno chiavi e ordine fissi.']);
        }

        return array_map(function (array $item, int $itemIndex) use ($expected, $allowedIcons, $index): array {
            $unknown = array_diff(array_keys($item), ['semantic_key', 'title', 'description', 'icon_key']);
            if ($unknown !== [] || ! isset($item['title'], $item['description'], $item['icon_key']) || ! in_array($item['icon_key'], $allowedIcons, true)) {
                throw ValidationException::withMessages(["sections.{$index}.data.items.{$itemIndex}" => 'Il passaggio contiene dati non consentiti.']);
            }

            return [
                'semantic_key' => $expected[$itemIndex],
                'title' => trim((string) $item['title']),
                'description' => trim((string) $item['description']),
                'icon_key' => (string) $item['icon_key'],
            ];
        }, array_values($items), array_keys(array_values($items)));
    }

    /** @return list<array{semantic_key: string, label: string, description: string}> */
    private function normalizeFixedSemanticRows(mixed $rows, int $index, string $field, array $expected, array $editable): array
    {
        if (! is_array($rows) || array_values(array_map(fn ($row) => is_array($row) ? $row['semantic_key'] ?? null : null, $rows)) !== $expected) {
            throw ValidationException::withMessages(["sections.{$index}.data.{$field}" => 'Le chiavi e l’ordine dei valori sono fissi.']);
        }

        return array_map(function (array $row, int $rowIndex) use ($expected, $editable, $field, $index): array {
            if (array_diff(array_keys($row), array_merge(['semantic_key'], $editable)) !== [] || ! isset($row['label'], $row['description'])) {
                throw ValidationException::withMessages(["sections.{$index}.data.{$field}.{$rowIndex}" => 'Il valore contiene dati non consentiti.']);
            }

            return ['semantic_key' => $expected[$rowIndex], 'label' => trim((string) $row['label']), 'description' => trim((string) $row['description'])];
        }, array_values($rows), array_keys(array_values($rows)));
    }

    /** @return list<array<string, mixed>> */
    private function normalizeTestimonials(mixed $testimonials, int $index): array
    {
        if (! is_array($testimonials)) {
            throw ValidationException::withMessages(["sections.{$index}.data.testimonials" => 'Le testimonianze non sono valide.']);
        }

        $allowedSources = ['google', 'miodottore', 'generic'];
        $normalized = [];
        foreach (array_values($testimonials) as $itemIndex => $testimonial) {
            if (! is_array($testimonial) || ! isset($testimonial['source_type'], $testimonial['quote'], $testimonial['author_name'], $testimonial['author_label'], $testimonial['avatar_text'])) {
                throw ValidationException::withMessages(["sections.{$index}.data.testimonials.{$itemIndex}" => 'La testimonianza è incompleta.']);
            }
            if (! in_array($testimonial['source_type'], $allowedSources, true)) {
                throw ValidationException::withMessages(["sections.{$index}.data.testimonials.{$itemIndex}.source_type" => 'La fonte selezionata non è consentita.']);
            }
            $normalized[] = [
                'source_type' => $testimonial['source_type'],
                'quote' => trim((string) $testimonial['quote']),
                'author_name' => trim((string) $testimonial['author_name']),
                'author_label' => trim((string) $testimonial['author_label']),
                'avatar_text' => trim((string) $testimonial['avatar_text']),
                'is_active' => $testimonial['is_active'] ?? true,
                'sort_order' => $itemIndex,
            ];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $payload */
    private function syncFaqs(Page $page, array $payload): void
    {
        if (PageSectionRegistry::hasDefinitionsFor((string) $page->internal_key)
            && (($payload['faqs'] ?? []) !== [] || ($payload['removed_faq_ids'] ?? []) !== [])) {
            throw ValidationException::withMessages([
                'faqs' => 'La pagina Il centro non prevede FAQ.',
            ]);
        }

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
