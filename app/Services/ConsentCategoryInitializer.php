<?php

namespace App\Services;

use App\Models\ConsentCategory;
use Illuminate\Support\Collection;

/** Ensures the public CMP always has its four stable category identities. */
class ConsentCategoryInitializer
{
    /** @return Collection<int, ConsentCategory> */
    public function initialize(): Collection
    {
        return collect([
            ['key' => 'necessary', 'name' => 'Necessari', 'description' => 'Indispensabili per il funzionamento del sito e la sicurezza. Sempre attivi.', 'default_state' => true, 'is_required' => true, 'is_active' => true, 'sort_order' => 0],
            ['key' => 'preferences', 'name' => 'Preferenze', 'description' => 'Ricordano le scelte non indispensabili e personalizzano l’esperienza.', 'default_state' => false, 'is_required' => false, 'is_active' => true, 'sort_order' => 1],
            ['key' => 'statistics', 'name' => 'Statistiche', 'description' => 'Aiutano a comprendere e migliorare l’esperienza in forma aggregata.', 'default_state' => false, 'is_required' => false, 'is_active' => true, 'sort_order' => 2],
            ['key' => 'marketing', 'name' => 'Marketing', 'description' => 'Abilitano comunicazioni e contenuti promozionali basati sulle preferenze.', 'default_state' => false, 'is_required' => false, 'is_active' => true, 'sort_order' => 3],
        ])->map(function (array $defaults): ConsentCategory {
            $category = ConsentCategory::query()->firstOrCreate(['key' => $defaults['key']], $defaults);
            $values = ['label' => $category->name, 'description' => $category->description];
            $revision = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            $category->translations()->updateOrCreate(['locale' => 'it'], [
                ...$values,
                'publication_state' => 'published',
                'source_revision' => $revision,
                'reviewed_source_revision' => $revision,
            ]);

            return $category;
        });
    }
}
