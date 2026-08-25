<?php

namespace App\Support\SiteIndexes;

final class SiteIndexPageRegistry
{
    public const KEYS = ['medical_areas_index', 'equipe_index', 'checkups_index', 'diagnostics_index', 'aesthetic_medicine_index'];

    public const AESTHETIC_CATEGORIES = ['face_proportions' => 'Volto e proporzioni', 'skin_quality' => 'Qualità della pelle', 'redness_dyschromia' => 'Rossori e discromie', 'body' => 'Corpo'];

    /** @return list<string> */
    public static function contentKeys(string $key): array
    {
        return match ($key) {
            'medical_areas_index' => ['eyebrow', 'title', 'body', 'search_placeholder', 'card_cta_label'],
            'equipe_index' => ['eyebrow', 'title', 'body', 'search_placeholder', 'filter_label', 'all_label', 'card_cta_label'],
            'checkups_index' => ['eyebrow', 'title', 'body', 'included_heading', 'detail_cta_label', 'final_cta_eyebrow', 'final_cta_title', 'final_cta_body', 'final_cta_label'],
            'diagnostics_index' => ['breadcrumb_label', 'eyebrow', 'title', 'body', 'primary_cta_label', 'catalog_title', 'catalog_body', 'all_label', 'search_placeholder', 'card_cta_label', 'contact_title', 'contact_body', 'contact_cta_label'],
            'aesthetic_medicine_index' => ['breadcrumb_label', 'eyebrow', 'title', 'body', 'primary_cta_label', 'secondary_cta_label', 'intro_title', 'intro_body', 'treatments_title', 'treatments_body', 'all_label', 'card_cta_label', 'evaluation_title', 'evaluation_body', 'approach_title', 'approach_body', 'team_title', 'team_body', 'faq_title', 'faq_body', 'final_title', 'final_body', 'final_cta_label'],
            default => throw new \InvalidArgumentException("Unknown site index key [{$key}]."),
        };
    }

    public static function contains(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    public static function defaults(string $key): array
    {
        $aestheticConfiguration = ['improvement_areas' => collect(self::AESTHETIC_CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'short_description' => '', 'cta_label' => 'Esplora'])->values()->all(), 'evaluation_steps' => [['title' => 'Ascolto e anamnesi', 'description' => ''], ['title' => 'Indicazione appropriata', 'description' => ''], ['title' => 'Piano condiviso', 'description' => '']], 'approach_principles' => collect(['MISURA', 'APPROPRIATEZZA', 'CONTINUITÀ'])->map(fn ($eyebrow) => ['eyebrow' => $eyebrow, 'title' => '', 'description' => ''])->all(), 'featured_professional_ids' => []];

        return match ($key) {
            'medical_areas_index' => ['title' => 'Aree mediche', 'slug' => 'aree-mediche', 'canonical_url' => '/aree-mediche', 'content' => ['eyebrow' => 'AREE MEDICHE', 'title' => 'Specialità e aree mediche', 'body' => 'Esplora le specialità Remedic e trova l’area più adatta alla tua esigenza.', 'search_placeholder' => 'Cerca una specialità...', 'card_cta_label' => 'Esplora l’area']],
            'equipe_index' => ['title' => 'Equipe', 'slug' => 'equipe', 'canonical_url' => '/equipe', 'content' => ['eyebrow' => 'I NOSTRI PROFESSIONISTI', 'title' => 'Professionisti diversi, un metodo condiviso', 'body' => 'Conosci i professionisti Remedic e trova lo specialista più adatto alla tua esigenza.', 'search_placeholder' => 'Cerca un professionista...', 'filter_label' => 'Filtra', 'all_label' => 'Tutti', 'card_cta_label' => 'Vedi profilo']],
            'checkups_index' => ['title' => 'Check-up e prevenzione', 'slug' => 'check-up', 'canonical_url' => '/check-up', 'content' => ['eyebrow' => 'CHECK-UP E PREVENZIONE', 'title' => 'Prevenzione costruita intorno a te', 'body' => 'Percorsi pensati per approfondire aspetti specifici della salute e orientare la prevenzione in modo più consapevole.', 'included_heading' => 'Cosa comprende', 'detail_cta_label' => 'Scopri il percorso', 'final_cta_eyebrow' => 'NON TROVI IL PERCORSO ADATTO?', 'final_cta_title' => 'Costruiamo un check-up personalizzato intorno alle tue esigenze.', 'final_cta_body' => 'Se nessuno dei percorsi disponibili risponde alle tue necessità, possiamo aiutarti a individuare un percorso di prevenzione più adatto alla tua situazione.', 'final_cta_label' => 'Richiedi un check-up personalizzato']],
            'diagnostics_index' => ['title' => 'Diagnostica', 'slug' => 'diagnostica', 'canonical_url' => '/diagnostica', 'content' => ['breadcrumb_label' => 'Diagnostica', 'eyebrow' => '', 'title' => 'La diagnostica che completa il percorso.', 'body' => 'Approfondimenti più semplici da coordinare.', 'primary_cta_label' => 'Esplora gli esami', 'catalog_title' => 'Esami diagnostici', 'catalog_body' => 'Esplora gli esami disponibili e usa i filtri per trovare la prestazione di interesse.', 'all_label' => 'Tutti', 'search_placeholder' => 'Cerca un esame...', 'card_cta_label' => 'Scopri l’esame', 'contact_title' => 'Non sai quale esame prenotare?', 'contact_body' => 'Se hai un’indicazione medica o hai bisogno di informazioni, possiamo aiutarti a orientarti tra gli esami disponibili.', 'contact_cta_label' => 'Contattaci'], 'configuration' => []],
            'aesthetic_medicine_index' => ['title' => 'Medicina estetica', 'slug' => 'medicina-estetica', 'canonical_url' => '/medicina-estetica', 'content' => ['breadcrumb_label' => 'Medicina estetica', 'eyebrow' => '', 'title' => 'La medicina estetica, con misura.', 'body' => 'Proporzioni e identità restano al centro.', 'primary_cta_label' => 'Scopri i trattamenti', 'secondary_cta_label' => 'Prenota una consulenza', 'intro_title' => 'Risultati che non devono sembrare trattamenti.', 'intro_body' => '', 'treatments_title' => 'I trattamenti', 'treatments_body' => 'Ogni procedura entra in un percorso definito dopo la valutazione, con obiettivi e priorità condivisi.', 'all_label' => 'Tutti', 'card_cta_label' => 'Scopri il trattamento', 'evaluation_title' => 'Prima del trattamento, viene la valutazione.', 'evaluation_body' => 'La qualità del percorso dipende dalla capacità di comprendere la persona, chiarire le aspettative e riconoscere anche quando non intervenire.', 'approach_title' => 'Il nostro approccio', 'approach_body' => 'Tre principi guidano il modo in cui costruiamo ogni indicazione.', 'team_title' => 'L’équipe di medicina estetica', 'team_body' => 'Competenze mediche, ascolto e continuità per costruire indicazioni proporzionate alla persona.', 'faq_title' => 'Domande frequenti', 'faq_body' => 'Le informazioni generali per avvicinarsi alla medicina estetica con consapevolezza.', 'final_title' => 'Ogni trattamento parte da una valutazione.', 'final_body' => 'Parla con il professionista per comprendere possibilità, priorità e percorso più appropriato.', 'final_cta_label' => 'Prenota una consulenza'], 'configuration' => $aestheticConfiguration],
            default => throw new \InvalidArgumentException("Unknown site index key [{$key}]."),
        };
    }
}
