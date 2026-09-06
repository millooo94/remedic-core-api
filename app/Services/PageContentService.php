<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\ConventionPartner;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Section;
use App\Support\Navigation\SiteNavigationRegistry;
use App\Support\Pages\HomePageRegistry;
use App\Support\Pages\LegalDocumentRegistry;
use App\Support\Pages\PageSectionRegistry;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class PageContentService
{
    /** @param array<string, mixed> $payload */
    public function sync(Page $page, array $payload): bool
    {
        $initialized = $this->initializeMissingSections($page);
        $sectionsChanged = $this->syncSections($page, $payload);
        $faqsChanged = $this->syncFaqs($page, $payload);

        return $initialized || $sectionsChanged || $faqsChanged;
    }

    public function initializeMissingSections(Page $page): bool
    {
        $missing = PageSectionRegistry::missingDefaults($page);
        foreach ($missing as $section) {
            $page->sections()->create([...$section, 'internal_title' => $section['title']]);
        }

        return $missing !== [];
    }

    /** @param array<string, mixed> $payload */
    private function syncSections(Page $page, array $payload): bool
    {
        if (! array_key_exists('sections', $payload) && ! array_key_exists('removed_section_keys', $payload)) {
            return false;
        }

        $changed = false;

        $removedKeys = array_values(array_unique($payload['removed_section_keys'] ?? []));
        if (PageSectionRegistry::hasDefinitionsFor((string) $page->internal_key)
            && array_intersect($removedKeys, array_keys(PageSectionRegistry::definitions((string) $page->internal_key))) !== []) {
            throw ValidationException::withMessages([
                'removed_section_keys' => 'Le sezioni tipizzate richieste dalla pagina non possono essere rimosse.',
            ]);
        }
        if ($removedKeys !== []) {
            $changed = $page->sections()->whereIn('key', $removedKeys)->delete() > 0;
        }

        if (! array_key_exists('sections', $payload)) {
            return $changed;
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
                'title', 'internal_title', 'subtitle', 'content', 'extra_json', 'is_active',
            ]);

            if (PageSectionRegistry::hasDefinitionsFor((string) $page->internal_key)) {
                $attributes = $this->typedSectionAttributes($page, $section, $sectionPayload, $index);
            }

            $section->fill($attributes);

            if ($section->isDirty()) {
                $section->save();
                $changed = true;
            }
        }

        return $changed;
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

        if (LegalDocumentRegistry::isLegal((string) $page->internal_key)) {
            return $this->legalSectionAttributes($section, $payload, $data, $index, (string) $page->internal_key);
        }
        if ((string) $page->internal_key === HomePageRegistry::INTERNAL_KEY) {
            return $this->homeSectionAttributes($section, $payload, $data, $index);
        }

        $allowedData = $this->allowedData((string) $page->internal_key, $section->key);

        $unknownData = array_diff(array_keys($data), $allowedData);
        if ($unknownData !== []) {
            throw ValidationException::withMessages([
                "sections.{$index}.data" => 'Il payload contiene campi non consentiti.',
            ]);
        }

        $extra = $section->extra_json ?? [];
        foreach (['eyebrow', 'link_label', 'image_alt', 'disclaimer', 'callout_eyebrow', 'callout_body', 'subheading', 'privacy_text', 'privacy_target', 'cta_label', 'cta_target', 'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target'] as $key) {
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

        if ((string) $page->internal_key === PageSectionRegistry::CONTACT_INTERNAL_KEY && $section->key === 'location_and_contacts') {
            $target = (string) ($extra['cta_target'] ?? 'contact');
            if ($target === 'external_url' || ! SiteNavigationRegistry::targetExists($target)) {
                throw ValidationException::withMessages(["sections.{$index}.data.cta_target" => 'La destinazione CTA non è valida.']);
            }
            $extra['cta_target'] = $target;
        }

        foreach (['cta_target', 'primary_cta_target', 'secondary_cta_target'] as $targetKey) {
            if (! array_key_exists($targetKey, $data)) {
                continue;
            }

            $target = (string) $extra[$targetKey];
            if ($target === 'external_url' || ! SiteNavigationRegistry::targetExists($target)) {
                throw ValidationException::withMessages(["sections.{$index}.data.{$targetKey}" => 'La destinazione CTA non è valida.']);
            }
        }

        if ((string) $page->internal_key === PageSectionRegistry::CAREERS_INTERNAL_KEY && $section->key === 'application' && array_key_exists('privacy_target', $data)) {
            $target = (string) $extra['privacy_target'];
            if (! SiteNavigationRegistry::targetExists($target) || array_key_exists($target, SiteNavigationRegistry::ACTIONS)) {
                throw ValidationException::withMessages(["sections.{$index}.data.privacy_target" => 'La destinazione della nota privacy non è valida.']);
            }
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
            'internal_title' => array_key_exists('internal_title', $payload) ? $payload['internal_title'] : $section->internal_title,
            'content' => array_key_exists('intro', $data) ? $data['intro'] : (array_key_exists('body', $data) ? $data['body'] : $section->content),
            'extra_json' => $extra,
            'sort_order' => $section->sort_order,
            'is_active' => $payload['is_active'] ?? $section->is_active,
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $data @return array<string,mixed> */
    private function legalSectionAttributes(Section $section, array $payload, array $data, int $index, string $internalKey): array
    {
        $hero = $section->key === LegalDocumentRegistry::HERO_KEY;
        $allowed = $hero ? ['eyebrow', 'body'] : ['blocks'];
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(["sections.{$index}.data" => 'Il payload legale contiene campi non consentiti.']);
        }
        $extra = $section->extra_json ?? [];
        if ($hero) {
            $extra['eyebrow'] = trim((string) ($data['eyebrow'] ?? $extra['eyebrow'] ?? ''));

            return ['title' => $payload['title'] ?? $section->title, 'internal_title' => $payload['internal_title'] ?? $section->internal_title, 'content' => $data['body'] ?? $section->content, 'extra_json' => $extra, 'sort_order' => 0, 'is_active' => true];
        }
        if (array_key_exists('blocks', $data)) {
            $extra['blocks'] = $this->normalizeLegalBlocks($data['blocks'], $index, $internalKey, $section->key);
        }

        return ['title' => $payload['title'] ?? $section->title, 'internal_title' => $payload['internal_title'] ?? $section->internal_title, 'content' => null, 'extra_json' => $extra, 'sort_order' => $section->sort_order, 'is_active' => $payload['is_active'] ?? $section->is_active];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $data @return array<string,mixed> */
    private function homeSectionAttributes(Section $section, array $payload, array $data, int $index): array
    {
        $defaults = HomePageRegistry::defaults($section->key);
        // The typed Angular editor shares a strongly typed form shell with the
        // other closed pages. Ignore fields belonging to another editor family;
        // only registered fields for this specific Homepage section persist.
        $data = array_intersect_key($data, $defaults);
        $extra = $section->extra_json ?? [];
        foreach ($data as $key => $value) {
            $extra[$key] = $value;
        }
        if (isset($extra['max_items']) && (! is_int($extra['max_items']) || $extra['max_items'] < 1 || $extra['max_items'] > 24)) {
            throw ValidationException::withMessages(["sections.{$index}.data.max_items" => 'Il numero massimo deve essere compreso tra 1 e 24.']);
        }
        if (isset($extra['selection_mode']) && ! in_array($extra['selection_mode'], ['automatic', 'manual'], true)) {
            throw ValidationException::withMessages(["sections.{$index}.data.selection_mode" => 'La modalità di selezione non è valida.']);
        }
        if ($section->key === 'hero') {
            foreach (['primary_cta_target', 'secondary_cta_target'] as $key) {
                $target = (string) ($extra[$key] ?? $defaults[$key] ?? '');
                if (! SiteNavigationRegistry::targetExists($target)) {
                    throw ValidationException::withMessages(["sections.{$index}.data.{$key}" => 'La destinazione CTA non è valida.']);
                }
                $extra[$key] = $target;
                $this->validateHomeActionContext($target, $extra, $key, $index);
            }
        }
        if ($section->key === 'contact') {
            foreach (['primary_cta_target', 'secondary_cta_target'] as $key) {
                $target = (string) ($extra[$key] ?? $defaults[$key] ?? '');
                if (! SiteNavigationRegistry::targetExists($target)) {
                    throw ValidationException::withMessages(["sections.{$index}.data.{$key}" => 'La destinazione CTA non è valida.']);
                }
                $extra[$key] = $target;
                $this->validateHomeActionContext($target, $extra, $key, $index);
            }
        }
        if ($section->key === 'newsletter') {
            $target = (string) ($extra['submit_target'] ?? $defaults['submit_target'] ?? 'newsletter_subscription');
            if (! SiteNavigationRegistry::targetExists($target)) {
                throw ValidationException::withMessages(["sections.{$index}.data.submit_target" => 'La destinazione CTA non è valida.']);
            }
            $extra['submit_target'] = $target;
            $this->validateHomeActionContext($target, $extra, 'submit', $index);
            if (! is_array($extra['benefits'] ?? null) || count($extra['benefits']) !== 3 || collect($extra['benefits'])->contains(fn ($benefit) => ! is_string($benefit))) {
                throw ValidationException::withMessages(["sections.{$index}.data.benefits" => 'La newsletter richiede esattamente tre benefit testuali.']);
            }
        }
        if (in_array($section->key, ['center_intro', 'conventions', 'faq'], true)) {
            $target = (string) ($extra['cta_target'] ?? $defaults['cta_target'] ?? '');
            if (! SiteNavigationRegistry::targetExists($target)) {
                throw ValidationException::withMessages(["sections.{$index}.data.cta_target" => 'La destinazione CTA non è valida.']);
            }
            $extra['cta_target'] = $target;
            $this->validateHomeActionContext($target, $extra, 'cta', $index);
        }
        if ($section->key === 'health_pills') {
            $ids = array_filter([(int) ($extra['featured_blog_post_id'] ?? 0), ...array_map('intval', $extra['secondary_blog_post_ids'] ?? [])]);
            if (count($ids) !== count(array_unique($ids)) || count($ids) > 3 || BlogPost::query()->whereIn('id', $ids)->where('content_type', 'health_pill')->count() !== count($ids)) {
                throw ValidationException::withMessages(["sections.{$index}.data" => 'Le Pillole selezionate devono essere uniche e di tipo health_pill.']);
            }
        }
        if ($section->key === 'conventions') {
            if (! is_array($extra['partner_ids'] ?? [])) {
                throw ValidationException::withMessages(["sections.{$index}.data.partner_ids" => 'Le convenzioni selezionate non sono valide.']);
            }
            $ids = array_map('intval', $extra['partner_ids']);
            $persistedIds = is_array($section->extra_json['partner_ids'] ?? null)
                ? array_map('intval', $section->extra_json['partner_ids'])
                : [];
            $newIds = array_values(array_diff($ids, $persistedIds));
            if (count($ids) > 2 || count($ids) !== count(array_unique($ids)) || ConventionPartner::query()->whereIn('id', $newIds)->where('is_active', true)->count() !== count($newIds)) {
                throw ValidationException::withMessages(["sections.{$index}.data.partner_ids" => 'Seleziona al massimo due convenzioni attive e diverse.']);
            }
        }

        $title = is_string($payload['title'] ?? null) && trim($payload['title']) !== ''
            ? trim($payload['title'])
            : ($section->title ?: PageSectionRegistry::definition(HomePageRegistry::INTERNAL_KEY, $section->key)['label']);

        return ['title' => $title, 'internal_title' => $payload['internal_title'] ?? $section->internal_title, 'content' => null, 'extra_json' => $extra, 'sort_order' => $section->sort_order, 'is_active' => $payload['is_active'] ?? $section->is_active];
    }

    /** @param array<string, mixed> $extra */
    private function validateHomeActionContext(string $target, array &$extra, string $prefix, int $index): void
    {
        $externalUrlKey = $prefix === 'cta' ? 'cta_external_url' : $prefix.'_external_url';
        $whatsappMessageKey = $prefix === 'cta' ? 'cta_whatsapp_message' : $prefix.'_whatsapp_message';
        $url = $extra[$externalUrlKey] ?? null;
        if ($target === 'external_url') {
            $valid = is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https';
            if (! $valid) {
                throw ValidationException::withMessages(["sections.{$index}.data.{$externalUrlKey}" => 'Inserisci un URL HTTPS valido.']);
            }
            $extra[$externalUrlKey] = trim($url);
        }
        if (isset($extra[$whatsappMessageKey]) && (! is_string($extra[$whatsappMessageKey]) || mb_strlen($extra[$whatsappMessageKey]) > 1000)) {
            throw ValidationException::withMessages(["sections.{$index}.data.{$whatsappMessageKey}" => 'Il messaggio WhatsApp non Ã¨ valido.']);
        }
    }

    /** @return list<array<string,mixed>> */
    private function normalizeLegalBlocks(mixed $blocks, int $index, string $internalKey, string $sectionKey): array
    {
        if (! is_array($blocks)) {
            throw ValidationException::withMessages(["sections.{$index}.data.blocks" => 'I blocchi legali non sono validi.']);
        }

        $normalized = array_map(function ($block, $blockIndex) use ($index): array {
            if (! is_array($block) || ! in_array($block['type'] ?? null, ['paragraph', 'subheading', 'bullet_list'], true)) {
                throw ValidationException::withMessages(["sections.{$index}.data.blocks.{$blockIndex}" => 'Tipo di blocco legale non consentito.']);
            }
            if (($block['type'] ?? null) === 'bullet_list') {
                $unknown = array_diff(array_keys($block), ['type', 'intro', 'items', 'outro']);
                if ($unknown !== []) {
                    throw ValidationException::withMessages(["sections.{$index}.data.blocks.{$blockIndex}" => 'Il blocco elenco contiene campi non consentiti.']);
                }

                return [
                    'type' => 'bullet_list',
                    'intro' => filled($block['intro'] ?? null) ? trim((string) $block['intro']) : null,
                    'items' => array_values(array_map(fn ($item) => trim((string) $item), $block['items'] ?? [])),
                    'outro' => filled($block['outro'] ?? null) ? trim((string) $block['outro']) : null,
                ];
            }

            if (array_diff(array_keys($block), ['type', 'text', 'links']) !== []) {
                throw ValidationException::withMessages(["sections.{$index}.data.blocks.{$blockIndex}" => 'Il blocco legale contiene campi non consentiti.']);
            }

            $text = trim((string) ($block['text'] ?? ''));
            $links = $this->normalizeLegalLinks($block['links'] ?? [], $text, $index, $blockIndex);

            return ['type' => (string) $block['type'], 'text' => $text, 'links' => $links];
        }, array_values($blocks), array_keys(array_values($blocks)));

        $targets = LegalDocumentRegistry::fixedPlaceholderTargets($internalKey, $sectionKey);
        if ($targets !== null) {
            $this->validateFixedPlaceholderContract($normalized, $targets, $index);
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $blocks @param list<string> $targets */
    private function validateFixedPlaceholderContract(array $blocks, array $targets, int $index): void
    {
        $placeholders = [];
        $links = [];
        foreach ($blocks as $block) {
            if (! is_string($block['text'] ?? null)) {
                continue;
            }
            preg_match_all('/\{\{([1-9][0-9]*)\}\}/', $block['text'], $matches);
            $placeholders = [...$placeholders, ...array_map('intval', $matches[1] ?? [])];
            $links = [...$links, ...(is_array($block['links'] ?? null) ? $block['links'] : [])];
        }

        if ($placeholders !== range(1, count($targets)) || count($links) !== count($targets) || array_values(array_column($links, 'target')) !== $targets) {
            throw ValidationException::withMessages(["sections.{$index}.data.blocks" => 'Questa sezione richiede esattamente due collegamenti configurabili: email e telefono.']);
        }
    }

    /** @return list<array<string,mixed>> */
    private function normalizeLegalLinks(mixed $links, string $text, int $index, int $blockIndex): array
    {
        if (! is_array($links)) {
            throw ValidationException::withMessages(["sections.{$index}.data.blocks.{$blockIndex}.links" => 'I collegamenti del paragrafo non sono validi.']);
        }
        preg_match_all('/\\{\\{([1-9][0-9]*)\\}\\}/', $text, $matches);
        $placeholders = array_map('intval', $matches[1] ?? []);
        $expected = $placeholders === [] ? [] : range(1, count($placeholders));
        if ($placeholders !== $expected || count(array_unique($placeholders)) !== count($placeholders) || count($links) !== count($placeholders)) {
            throw ValidationException::withMessages(["sections.{$index}.data.blocks.{$blockIndex}.links" => 'I placeholder devono essere sequenziali e avere un solo collegamento configurato.']);
        }

        return array_map(function ($link, int $linkIndex) use ($index, $blockIndex): array {
            if (! is_array($link) || array_diff(array_keys($link), ['placeholder', 'target', 'label']) !== []) {
                throw ValidationException::withMessages(["sections.{$index}.data.blocks.{$blockIndex}.links.{$linkIndex}" => 'Configurazione collegamento non consentita.']);
            }
            $placeholder = (int) ($link['placeholder'] ?? 0);
            $target = trim((string) ($link['target'] ?? ''));
            $isCenterValue = in_array($target, ['owner_email', 'owner_phone', 'center_address'], true);
            $isSharedPage = SiteNavigationRegistry::targetExists($target) && ! array_key_exists($target, SiteNavigationRegistry::ACTIONS);
            if ($placeholder !== $linkIndex + 1 || ! $isCenterValue && ! $isSharedPage) {
                throw ValidationException::withMessages(["sections.{$index}.data.blocks.{$blockIndex}.links.{$linkIndex}" => 'Destinazione del collegamento non valida.']);
            }

            return array_filter([
                'placeholder' => $placeholder,
                'target' => $target,
                'label' => filled($link['label'] ?? null) ? trim((string) $link['label']) : null,
            ], static fn (mixed $value): bool => $value !== null);
        }, array_values($links), array_keys(array_values($links)));
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
                'patient_experiences' => ['eyebrow', 'body'],
                'plus_health_protocol_cta' => ['body', 'link_label', 'cta_target'],
                'orientation_cta' => ['body', 'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'promise' => ['eyebrow', 'body', 'values'],
                'four_pillars' => ['eyebrow', 'body', 'pillars'],
                'care_path_overview' => ['body', 'items'],
                'personalized_care_plan', 'clinical_technology' => ['body', 'image_alt'],
                'patient_education' => ['body', 'callout_eyebrow', 'callout_body'],
                'person_first' => ['eyebrow', 'body', 'image_alt', 'items'],
                'method_statement' => ['eyebrow', 'body'],
                'orientation_cta' => ['body', 'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::CONTACT_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'location_and_contacts' => ['intro', 'cta_label', 'cta_target'],
                'orientation_cta' => ['body', 'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::CONVENTIONS_NETWORK_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'access_process' => ['intro', 'items'],
                'conventions_catalog' => ['intro'],
                'contact_cta' => ['body', 'primary_cta_label', 'primary_cta_target'],
                default => [],
            };
        }

        if ($internalKey === PageSectionRegistry::CAREERS_INTERNAL_KEY) {
            return match ($sectionKey) {
                'hero' => ['eyebrow', 'body', 'image_alt'],
                'professional_profiles' => ['intro', 'subheading', 'items'],
                'what_we_look_for' => ['intro', 'items'],
                'application' => ['body', 'cta_label', 'privacy_text', 'privacy_target'],
                default => [],
            };
        }

        return match ($sectionKey) {
            'hero' => ['eyebrow', 'body', 'image_alt'],
            'intro' => ['body'],
            'coordinated_care', 'continuity' => ['body', 'image_alt'],
            'why_remedic', 'plus_health_protocol' => ['eyebrow', 'body', 'link_label', 'cta_target', 'image_alt'],
            'orientation_cta' => ['body', 'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target'],
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
        $defaults = PageSectionRegistry::protocolPillars();
        $labels = array_column($defaults, 'label');
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
                'detail_eyebrow' => $defaults[$pillarIndex]['detail_eyebrow'],
                'detail_title' => filled($pillar['detail_title'] ?? null) ? trim((string) $pillar['detail_title']) : $defaults[$pillarIndex]['detail_title'],
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
        $icons = ['message', 'clipboard', 'microscope', 'info'];
        if (! is_array($items) || array_values(array_map(fn ($item) => is_array($item) ? $item['semantic_key'] ?? null : null, $items)) !== $expected) {
            throw ValidationException::withMessages(["sections.{$index}.data.items" => 'I quattro passaggi hanno chiavi e ordine fissi.']);
        }

        return array_map(function (array $item, int $itemIndex) use ($expected, $icons, $index): array {
            $unknown = array_diff(array_keys($item), ['semantic_key', 'title', 'description', 'icon_key']);
            if ($unknown !== [] || ! isset($item['title'], $item['description'], $item['icon_key']) || $item['icon_key'] !== $icons[$itemIndex]) {
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
    private function syncFaqs(Page $page, array $payload): bool
    {
        if ((string) $page->internal_key !== HomePageRegistry::INTERNAL_KEY && PageSectionRegistry::hasDefinitionsFor((string) $page->internal_key)
            && (($payload['faqs'] ?? []) !== [] || ($payload['removed_faq_ids'] ?? []) !== [])) {
            throw ValidationException::withMessages([
                'faqs' => 'La pagina Il centro non prevede FAQ.',
            ]);
        }

        if (! array_key_exists('faqs', $payload) && ! array_key_exists('removed_faq_ids', $payload)) {
            return false;
        }

        $changed = false;

        $removedIds = array_values(array_unique(array_map('intval', $payload['removed_faq_ids'] ?? [])));
        if ($removedIds !== []) {
            $changed = $page->faqs()->whereIn('id', $removedIds)->delete() > 0;
        }

        if (! array_key_exists('faqs', $payload)) {
            return $changed;
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
                'sort_order' => $index,
                'is_active' => $faqPayload['is_active'] ?? true,
                'is_structured_data' => $faqPayload['is_structured_data'] ?? true,
            ]);

            if ($faq->isDirty()) {
                $faq->save();
                $changed = true;
            }
        }

        return $changed;
    }
}
