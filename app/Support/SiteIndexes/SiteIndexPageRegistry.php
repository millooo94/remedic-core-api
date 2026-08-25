<?php

namespace App\Support\SiteIndexes;

final class SiteIndexPageRegistry
{
    public const KEYS = ['medical_areas_index', 'equipe_index', 'checkups_index'];

    /** @return list<string> */
    public static function contentKeys(string $key): array
    {
        return match ($key) {
            'medical_areas_index' => ['eyebrow', 'title', 'body', 'search_placeholder', 'card_cta_label'],
            'equipe_index' => ['eyebrow', 'title', 'body', 'search_placeholder', 'filter_label', 'all_label', 'card_cta_label'],
            'checkups_index' => ['eyebrow', 'title', 'body', 'included_heading', 'detail_cta_label', 'final_cta_eyebrow', 'final_cta_title', 'final_cta_body', 'final_cta_label'],
            default => throw new \InvalidArgumentException("Unknown site index key [{$key}]."),
        };
    }

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public static function defaults(string $key): array
    {
        return match ($key) {
            'medical_areas_index' => ['title' => 'Aree mediche', 'slug' => 'aree-mediche', 'canonical_url' => '/aree-mediche', 'content' => ['eyebrow' => 'AREE MEDICHE', 'title' => 'Specialità e aree mediche', 'body' => 'Esplora le specialità Remedic e trova l’area più adatta alla tua esigenza.', 'search_placeholder' => 'Cerca una specialità...', 'card_cta_label' => 'Esplora l’area']],
            'equipe_index' => ['title' => 'Equipe', 'slug' => 'equipe', 'canonical_url' => '/equipe', 'content' => ['eyebrow' => 'I NOSTRI PROFESSIONISTI', 'title' => 'Professionisti diversi, un metodo condiviso', 'body' => 'Conosci i professionisti Remedic e trova lo specialista più adatto alla tua esigenza.', 'search_placeholder' => 'Cerca un professionista...', 'filter_label' => 'Filtra', 'all_label' => 'Tutti', 'card_cta_label' => 'Vedi profilo']],
            'checkups_index' => ['title' => 'Check-up e prevenzione', 'slug' => 'check-up', 'canonical_url' => '/check-up', 'content' => ['eyebrow' => 'CHECK-UP E PREVENZIONE', 'title' => 'Prevenzione costruita intorno a te', 'body' => 'Percorsi pensati per approfondire aspetti specifici della salute e orientare la prevenzione in modo più consapevole.', 'included_heading' => 'Cosa comprende', 'detail_cta_label' => 'Scopri il percorso', 'final_cta_eyebrow' => 'NON TROVI IL PERCORSO ADATTO?', 'final_cta_title' => 'Costruiamo un check-up personalizzato intorno alle tue esigenze.', 'final_cta_body' => 'Se nessuno dei percorsi disponibili risponde alle tue necessità, possiamo aiutarti a individuare un percorso di prevenzione più adatto alla tua situazione.', 'final_cta_label' => 'Richiedi un check-up personalizzato']],
            default => throw new \InvalidArgumentException("Unknown site index key [{$key}]."),
        };
    }
}
