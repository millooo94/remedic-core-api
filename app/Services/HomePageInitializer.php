<?php

namespace App\Services;

use App\Models\Page;
use App\Support\Pages\HomePageRegistry;

class HomePageInitializer
{
    public function __construct(private readonly PageContentService $content) {}

    public function initialize(): Page
    {
        $page = Page::query()->firstOrCreate(
            ['internal_key' => HomePageRegistry::INTERNAL_KEY],
            ['title' => 'Homepage', 'slug' => Page::HOME_SLUG, 'template' => 'default', 'canonical_url' => '/', 'faq_enabled' => true, 'is_active' => true],
        );
        if (! $page->is_active) {
            $page->forceFill(['is_active' => true])->save();
        }
        $this->content->initializeMissingSections($page);
        $page->faqs()->firstOrCreate(
            ['question' => 'Come posso prenotare una visita presso Remedic?'],
            ['answer' => 'Puoi prenotare online con il pulsante “Prenota ora”, che avvia il percorso guidato in base alla disponibilità reale dei professionisti, oppure contattarci per essere seguito dal nostro personale di accoglienza.', 'sort_order' => 0, 'is_active' => true, 'is_structured_data' => true],
        );

        return $page->fresh();
    }
}
