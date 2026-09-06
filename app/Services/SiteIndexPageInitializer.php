<?php

namespace App\Services;

use App\Models\Page;
use App\Models\SiteIndexPage;
use App\Support\SiteIndexes\SiteIndexPageRegistry;

class SiteIndexPageInitializer
{
    public function initialize(): void
    {
        foreach (SiteIndexPageRegistry::KEYS as $key) {
            $d = SiteIndexPageRegistry::defaults($key);
            $index = SiteIndexPage::query()->firstOrCreate(['internal_key' => $key], $d + ['is_active' => true]);
            if ($key === 'medical_areas_index') {
                $this->syncMedicalAreasSections($index);
            }
            if ($key === 'conventions_network_index' && $index->wasRecentlyCreated) {
                $this->migrateConventionsPage($index);
            }
        }
    }

    /** The catalogue is a derived projection, so only its visibility belongs to the index. */
    private function syncMedicalAreasSections(SiteIndexPage $index): void
    {
        $definitions = [
            'hero' => 'Hero / introduzione',
            'specialties_catalog' => 'Elenco specialità',
        ];

        foreach ($definitions as $sortOrder => $internalTitle) {
            $section = $index->sections()->firstOrNew(['key' => $sortOrder]);
            $section->fill([
                'internal_title' => $section->internal_title ?: $internalTitle,
                'title' => $section->title ?: $internalTitle,
                'content' => $section->content ?? '',
                'sort_order' => array_search($sortOrder, array_keys($definitions), true),
                'is_active' => $section->exists ? $section->is_active : true,
            ])->save();
        }

        $index->sections()->whereNotIn('key', array_keys($definitions))->delete();
    }

    private function migrateConventionsPage(SiteIndexPage $index): void
    {
        $legacy = Page::query()->with(['sections', 'faqs'])
            ->where('internal_key', Page::CONVENTIONS_NETWORK_INTERNAL_KEY)->first();
        if ($legacy === null) {
            return;
        }

        $sections = $legacy->sections->keyBy('key');
        $content = $index->content ?? [];
        $map = [
            ['hero', 'eyebrow', 'hero_eyebrow'], ['hero', 'title', 'hero_title'], ['hero', 'content', 'hero_body'],
            ['access_process', 'title', 'access_title'], ['access_process', 'content', 'access_body'],
            ['conventions_catalog', 'title', 'catalog_title'], ['conventions_catalog', 'content', 'catalog_body'],
            ['contact_cta', 'title', 'contact_title'], ['contact_cta', 'content', 'contact_body'],
        ];
        foreach ($map as [$sectionKey, $field, $contentKey]) {
            $section = $sections->get($sectionKey);
            $content[$contentKey] = $field === 'eyebrow' ? ($section?->extra_json['eyebrow'] ?? '') : ($section?->getAttribute($field) ?? '');
        }
        $index->fill([
            'title' => $legacy->title, 'canonical_url' => '/'.$legacy->slug, 'content' => $content,
            'seo_title' => $legacy->seo_title, 'seo_description' => $legacy->seo_description, 'seo_h1' => $legacy->seo_h1,
            'robots' => $legacy->robots, 'is_active' => $legacy->is_active,
            'configuration' => ['section_extras' => $legacy->sections->mapWithKeys(fn ($section) => [$section->key => $section->extra_json ?? []])->all()],
        ])->save();
        foreach ($legacy->sections as $section) {
            $index->sections()->create(['key' => $section->key, 'title' => $section->title, 'content' => $section->content, 'extra_json' => $section->extra_json, 'sort_order' => $section->sort_order, 'is_active' => $section->is_active]);
        }
        foreach ($legacy->faqs as $faq) {
            $index->faqs()->create(['question' => $faq->question, 'answer' => $faq->answer, 'sort_order' => $faq->sort_order, 'is_active' => $faq->is_active, 'is_structured_data' => $faq->is_structured_data]);
        }
    }
}
