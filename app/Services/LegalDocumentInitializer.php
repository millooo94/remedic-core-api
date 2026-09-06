<?php

namespace App\Services;

use App\Models\Page;
use App\Support\Pages\LegalDocumentContent;
use App\Support\Pages\LegalDocumentRegistry;

/** Performs the one-off, idempotent legal-document alignment. */
class LegalDocumentInitializer
{
    public function __construct(private readonly PageContentService $pageContent) {}

    /** @return array{privacy:Page,cookie:Page,terms:Page} */
    public function initialize(): array
    {
        $privacy = Page::query()->where('slug', 'privacy')->firstOrFail();
        $cookie = Page::query()->where('slug', 'cookie-policy')->firstOrFail();
        $terms = Page::query()->firstOrCreate(
            ['internal_key' => LegalDocumentRegistry::TERMS],
            ['title' => 'Termini di servizio', 'slug' => 'termini-di-servizio', 'template' => 'default', 'canonical_url' => '/termini-di-servizio', 'faq_enabled' => false, 'is_active' => true]
        );

        foreach ([$privacy, $cookie, $terms] as $page) {
            $this->renameLegacyKeys($page);
            $this->pageContent->initializeMissingSections($page);
            $this->synchronizeApprovedContentOnce($page);
            $this->upgradeToPlaceholderContract($page);
            $this->normalizeFixedPlaceholderSections($page);
            $this->fillMissingInternalTitles($page);
        }

        return compact('privacy', 'cookie', 'terms');
    }

    private function renameLegacyKeys(Page $page): void
    {
        $map = match ($page->slug) {
            'privacy' => ['hero' => 'legal_hero', 'titolare' => 'controller_contacts', 'dati-trattati' => 'personal_data', 'finalita' => 'purposes_legal_bases', 'conservazione' => 'retention', 'diritti' => 'data_subject_rights'],
            'cookie-policy' => ['hero' => 'legal_hero', 'cosa-sono' => 'cookies_technologies', 'cookie-tecnici' => 'strictly_necessary', 'cookie-analitici' => 'analytics', 'terze-parti' => 'first_third_party', 'preferenze' => 'manage_preferences', 'aggiornamenti' => 'policy_updates'],
            default => [],
        };
        foreach ($map as $from => $to) {
            $section = $page->sections()->where('key', $from)->first();
            if ($section !== null && $page->sections()->where('key', $to)->doesntExist()) {
                $section->update(['key' => $to]);
            }
        }

        $page->sections()->whereNotIn('key', LegalDocumentRegistry::sectionKeys((string) $page->internal_key))->delete();
    }

    private function synchronizeApprovedContentOnce(Page $page): void
    {
        $hero = $page->sections()->where('key', LegalDocumentRegistry::HERO_KEY)->firstOrFail();
        if (($hero->extra_json['legal_contract_version'] ?? 0) >= 1) {
            return;
        }

        foreach (LegalDocumentRegistry::definitions((string) $page->internal_key) as $key => $definition) {
            $section = $page->sections()->where('key', $key)->firstOrFail();
            $extra = $key === LegalDocumentRegistry::HERO_KEY
                ? ['eyebrow' => 'INFORMAZIONI LEGALI', 'blocks' => [], 'legal_contract_version' => 1]
                : ['blocks' => LegalDocumentContent::blocks((string) $page->internal_key, $key)];
            $section->update([
                'title' => $definition['label'],
                'content' => $key === LegalDocumentRegistry::HERO_KEY ? LegalDocumentRegistry::heroDescription((string) $page->internal_key) : null,
                'extra_json' => $extra,
                'sort_order' => $definition['default_sort_order'],
                'is_active' => true,
            ]);
        }
    }

    private function fillMissingInternalTitles(Page $page): void
    {
        foreach (LegalDocumentRegistry::definitions((string) $page->internal_key) as $key => $definition) {
            $section = $page->sections()->where('key', $key)->firstOrFail();
            if (blank($section->internal_title)) {
                $section->update(['internal_title' => $definition['label']]);
            }
        }
    }

    /** Convert legacy fragment blocks in place without replacing editorial copy. */
    private function upgradeToPlaceholderContract(Page $page): void
    {
        foreach ($page->sections()->where('key', '!=', LegalDocumentRegistry::HERO_KEY)->get() as $section) {
            $extra = $section->extra_json ?? [];
            $blocks = $extra['blocks'] ?? null;
            if (! is_array($blocks) || ! collect($blocks)->contains(fn (mixed $block): bool => is_array($block) && array_key_exists('parts', $block))) {
                continue;
            }
            $extra['blocks'] = LegalDocumentContent::toPlaceholderBlocks($blocks);
            $section->update(['extra_json' => $extra]);
        }

        $hero = $page->sections()->where('key', LegalDocumentRegistry::HERO_KEY)->firstOrFail();
        $extra = $hero->extra_json ?? [];
        if (($extra['legal_contract_version'] ?? 0) < 2) {
            $extra['legal_contract_version'] = 2;
            $hero->update(['extra_json' => $extra]);
        }
    }

    /** Enforce explicit two-link contracts without inferring stale legacy fragments. */
    private function normalizeFixedPlaceholderSections(Page $page): void
    {
        foreach (LegalDocumentRegistry::definitions((string) $page->internal_key) as $key => $_definition) {
            $targets = LegalDocumentRegistry::fixedPlaceholderTargets((string) $page->internal_key, $key);
            if ($targets === null) {
                continue;
            }

            $section = $page->sections()->where('key', $key)->firstOrFail();
            $extra = $section->extra_json ?? [];
            if ($this->hasFixedPlaceholderContract($extra['blocks'] ?? [], $targets)) {
                continue;
            }

            $extra['blocks'] = LegalDocumentContent::blocks((string) $page->internal_key, $key);
            $section->update(['extra_json' => $extra]);
        }
    }

    /** @param mixed $blocks @param list<string> $targets */
    private function hasFixedPlaceholderContract(mixed $blocks, array $targets): bool
    {
        if (! is_array($blocks)) {
            return false;
        }

        $placeholders = [];
        $links = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['text'] ?? null)) {
                continue;
            }
            preg_match_all('/\{\{([1-9][0-9]*)\}\}/', $block['text'], $matches);
            $placeholders = [...$placeholders, ...array_map('intval', $matches[1] ?? [])];
            $blockLinks = is_array($block['links'] ?? null) ? $block['links'] : [];
            $links = [...$links, ...$blockLinks];
        }

        return $placeholders === range(1, count($targets))
            && count($links) === count($targets)
            && array_values(array_map(static fn (mixed $link): mixed => is_array($link) ? $link['target'] ?? null : null, $links)) === $targets;
    }
}
