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
            ['title' => 'Termini di servizio', 'slug' => 'termini-di-servizio', 'template' => 'default', 'canonical_url' => '/termini-di-servizio', 'faq_enabled' => false, 'is_active' => true, 'published_at' => null]
        );

        foreach ([$privacy, $cookie, $terms] as $page) {
            $this->renameLegacyKeys($page);
            $this->pageContent->initializeMissingSections($page);
            $this->synchronizeApprovedContentOnce($page);
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
        if (($hero->extra_json['legal_contract_version'] ?? null) === 1) {
            return;
        }

        foreach (LegalDocumentRegistry::definitions((string) $page->internal_key) as $key => $definition) {
            $section = $page->sections()->where('key', $key)->firstOrFail();
            $extra = $key === LegalDocumentRegistry::HERO_KEY
                ? ['eyebrow' => 'INFORMAZIONI LEGALI', 'last_updated_on' => '2026-08-07', 'blocks' => [], 'legal_contract_version' => 1]
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
}
