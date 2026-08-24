<?php

namespace App\Support\Pages;

use App\Models\Page;

/** Closed editorial contracts for typed institutional Pages. */
final class PageSectionRegistry
{
    public const CENTER_INTERNAL_KEY = 'center';

    public const WHY_CHOOSE_US_INTERNAL_KEY = 'why_choose_us';

    public const CENTER_SECTION_KEYS = [
        'hero', 'intro', 'coordinated_care', 'continuity', 'why_remedic', 'plus_health_protocol', 'orientation_cta',
    ];

    public const WHY_CHOOSE_US_SECTION_KEYS = [
        'hero', 'model_overview', 'three_reasons', 'integrated_workflow', 'continuity', 'patient_experiences', 'plus_health_protocol_cta', 'orientation_cta',
    ];

    /**
     * @var array<string, array<string, array{label: string, summary: string, editor: string, default_sort_order: int, capabilities: list<string>, media_slot?: string, target_internal_key?: string, actions?: list<string>}>>
     */
    private const DEFINITIONS = [
        self::CENTER_INTERNAL_KEY => [
            'hero' => ['label' => 'Hero', 'summary' => 'Messaggio introduttivo e immagine principale.', 'editor' => 'center-hero', 'default_sort_order' => 0, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'intro' => ['label' => 'Introduzione', 'summary' => 'Presentazione editoriale del centro.', 'editor' => 'center-intro', 'default_sort_order' => 1, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'coordinated_care' => ['label' => 'Competenze coordinate', 'summary' => 'Competenze e diagnostica nello stesso centro.', 'editor' => 'center-image-text', 'default_sort_order' => 2, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'continuity' => ['label' => 'Continuità del percorso', 'summary' => 'Continuità tra controlli e approfondimenti.', 'editor' => 'center-image-text', 'default_sort_order' => 3, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'why_remedic' => ['label' => 'Perché scegliere Remedic', 'summary' => 'Valore editoriale con rimando semantico futuro.', 'editor' => 'center-linked-image-text', 'default_sort_order' => 4, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image', 'target_internal_key' => 'why_choose_us'],
            'plus_health_protocol' => ['label' => 'Protocollo Più Salute', 'summary' => 'Metodo Remedic con rimando semantico futuro.', 'editor' => 'center-linked-image-text', 'default_sort_order' => 5, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image', 'target_internal_key' => 'plus_health_protocol'],
            'orientation_cta' => ['label' => 'Orientamento finale', 'summary' => 'Invito finale con azioni globali del sito.', 'editor' => 'center-orientation-cta', 'default_sort_order' => 6, 'capabilities' => ['edit', 'toggle', 'reorder'], 'actions' => ['booking', 'contact']],
        ],
        self::WHY_CHOOSE_US_INTERNAL_KEY => [
            'hero' => ['label' => 'Hero', 'summary' => 'Introduzione alla scelta Remedic.', 'editor' => 'why-hero', 'default_sort_order' => 0, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'model_overview' => ['label' => 'Il modello Remedic', 'summary' => 'Panoramica con punti ordinabili.', 'editor' => 'why-model-overview', 'default_sort_order' => 1, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'three_reasons' => ['label' => 'Tre motivi', 'summary' => 'Motivi con icone dal catalogo controllato.', 'editor' => 'why-three-reasons', 'default_sort_order' => 2, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'integrated_workflow' => ['label' => 'Workflow integrato', 'summary' => 'Competenze e diagnostica coordinate.', 'editor' => 'why-image-text', 'default_sort_order' => 3, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'continuity' => ['label' => 'Continuità', 'summary' => 'Percorso senza ripartire da zero.', 'editor' => 'why-image-text', 'default_sort_order' => 4, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'patient_experiences' => ['label' => 'Esperienze dei pazienti', 'summary' => 'Testimonianze editoriali strutturate.', 'editor' => 'why-patient-experiences', 'default_sort_order' => 5, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'plus_health_protocol_cta' => ['label' => 'Protocollo Più Salute', 'summary' => 'CTA con destinazione semantica futura.', 'editor' => 'why-protocol-cta', 'default_sort_order' => 6, 'capabilities' => ['edit', 'toggle', 'reorder'], 'target_internal_key' => 'plus_health_protocol'],
            'orientation_cta' => ['label' => 'Orientamento finale', 'summary' => 'Invito con azioni globali del sito.', 'editor' => 'why-orientation-cta', 'default_sort_order' => 7, 'capabilities' => ['edit', 'toggle', 'reorder'], 'actions' => ['booking', 'contact']],
        ],
    ];

    /** @return array{label: string, summary: string, editor: string, default_sort_order: int, capabilities: list<string>, media_slot?: string, target_internal_key?: string, actions?: list<string>}|null */
    public static function definition(string $internalKey, string $sectionKey): ?array
    {
        return self::DEFINITIONS[$internalKey][$sectionKey] ?? null;
    }

    /** @return array<string, array{label: string, summary: string, editor: string, default_sort_order: int, capabilities: list<string>, media_slot?: string, target_internal_key?: string, actions?: list<string>}> */
    public static function definitions(string $internalKey): array
    {
        return self::DEFINITIONS[$internalKey] ?? [];
    }

    public static function hasDefinitionsFor(string $internalKey): bool
    {
        return array_key_exists($internalKey, self::DEFINITIONS);
    }

    public static function canCreate(string $internalKey, string $sectionKey): bool
    {
        return ! self::hasDefinitionsFor($internalKey) || self::definition($internalKey, $sectionKey) !== null;
    }

    public static function supportsMedia(Page $page, string $sectionKey, string $mediaSlot = 'image'): bool
    {
        return (self::definition((string) $page->internal_key, $sectionKey)['media_slot'] ?? null) === $mediaSlot;
    }

    /** @return list<array{key: string, title: string, content: string, extra_json: array<string, mixed>, sort_order: int, is_active: bool}> */
    public static function missingDefaults(Page $page): array
    {
        $internalKey = (string) $page->internal_key;
        if (! in_array($internalKey, [self::CENTER_INTERNAL_KEY, self::WHY_CHOOSE_US_INTERNAL_KEY], true)) {
            return [];
        }

        $existingKeys = $page->sections()->pluck('key')->all();

        $defaults = $internalKey === self::CENTER_INTERNAL_KEY ? self::centerDefaults() : self::whyChooseUsDefaults();

        return array_values(array_filter($defaults, fn (array $section): bool => ! in_array($section['key'], $existingKeys, true)));
    }

    /** @return list<array{key: string, title: string, content: string, extra_json: array<string, mixed>, sort_order: int, is_active: bool}> */
    private static function centerDefaults(): array
    {
        return [
            ['key' => 'hero', 'title' => 'Un centro, un unico percorso di cura', 'content' => 'Remedic riunisce competenze specialistiche, diagnostica e attenzione alla persona in un modello di cura coordinato, pensato per accompagnare il paziente nel tempo.', 'extra_json' => ['eyebrow' => 'IL CENTRO', 'image_path' => null, 'image_alt' => null], 'sort_order' => 0, 'is_active' => true],
            ['key' => 'intro', 'title' => 'Remedic, vicino alle persone', 'content' => 'Un centro medico multidisciplinare in cui specialità, diagnostica e prevenzione condividono un modello comune: rendere ogni passaggio più leggibile e mantenere un riferimento chiaro nel tempo.', 'extra_json' => [], 'sort_order' => 1, 'is_active' => true],
            ['key' => 'coordinated_care', 'title' => 'Competenze diverse, nello stesso centro', 'content' => 'Remedic nasce per mettere in relazione specialità, diagnostica e prevenzione. L’obiettivo è rendere più semplice per il paziente orientarsi tra esigenze diverse, mantenendo un riferimento chiaro lungo il percorso.', 'extra_json' => ['image_path' => null, 'image_alt' => null], 'sort_order' => 2, 'is_active' => true],
            ['key' => 'continuity', 'title' => 'Un percorso che continua nel tempo', 'content' => 'Quando il quadro clinico lo richiede, Remedic facilita controlli, approfondimenti e il coinvolgimento di competenze diverse, mantenendo continuità e chiarezza nel percorso.', 'extra_json' => ['image_path' => null, 'image_alt' => null], 'sort_order' => 3, 'is_active' => true],
            ['key' => 'why_remedic', 'title' => 'Perché scegliere Remedic', 'content' => 'Competenze coordinate, diagnostica integrata e attenzione alla persona sono alcuni degli elementi che rendono diverso il modo in cui Remedic costruisce il percorso di cura.', 'extra_json' => ['eyebrow' => 'PERCHÉ REMEDIC', 'link_label' => 'Scopri perché scegliere Remedic', 'target_internal_key' => 'why_choose_us', 'image_path' => null, 'image_alt' => null], 'sort_order' => 4, 'is_active' => true],
            ['key' => 'plus_health_protocol', 'title' => 'Protocollo Più Salute', 'content' => 'Quattro valori — professionalità, rapidità, accessibilità e umanità — diventano un metodo per accompagnare la persona lungo il percorso di cura.', 'extra_json' => ['eyebrow' => 'IL NOSTRO METODO', 'link_label' => 'Scopri il Protocollo Più Salute', 'target_internal_key' => 'plus_health_protocol', 'image_path' => null, 'image_alt' => null], 'sort_order' => 5, 'is_active' => true],
            ['key' => 'orientation_cta', 'title' => 'Hai bisogno di orientarti?', 'content' => 'Contattaci per capire quale visita, esame o percorso può essere più adatto alla tua esigenza.', 'extra_json' => ['actions' => ['booking', 'contact']], 'sort_order' => 6, 'is_active' => true],
        ];
    }

    /** @return list<array{key: string, title: string, content: string, extra_json: array<string, mixed>, sort_order: int, is_active: bool}> */
    private static function whyChooseUsDefaults(): array
    {
        return [
            ['key' => 'hero', 'title' => 'Perché scegliere Remedic', 'content' => 'Un percorso di cura funziona meglio quando competenze, diagnostica, informazioni e attenzione alla persona riescono a lavorare insieme.', 'extra_json' => ['eyebrow' => 'PERCHÉ REMEDIC', 'image_path' => null, 'image_alt' => null], 'sort_order' => 0, 'is_active' => true],
            ['key' => 'model_overview', 'title' => 'Più competenze, un unico percorso', 'content' => 'Remedic riunisce specialità mediche e diagnostica nello stesso centro per rendere più semplice il passaggio dalla valutazione clinica agli eventuali approfondimenti e controlli successivi.', 'extra_json' => ['eyebrow' => 'IL MODELLO REMEDIC', 'items' => [
                ['title' => 'Competenze che lavorano insieme', 'description' => 'Specialità differenti possono condividere informazioni e orientare il percorso quando serve una valutazione coordinata.'],
                ['title' => 'Diagnostica integrata', 'description' => 'Visite ed esami nello stesso centro facilitano gli approfondimenti e riducono passaggi non necessari.'],
                ['title' => 'Informazioni più coordinate', 'description' => 'I passaggi successivi restano più leggibili quando professionisti e strumenti fanno parte dello stesso percorso.'],
                ['title' => 'Attenzione alla persona', 'description' => 'Ascolto, chiarezza e continuità aiutano a costruire il percorso intorno alle esigenze reali della persona.'],
            ]], 'sort_order' => 1, 'is_active' => true],
            ['key' => 'three_reasons', 'title' => 'Tre motivi per scegliere Remedic', 'content' => 'Un modello che mette in relazione competenze, strumenti e attenzione alla persona.', 'extra_json' => ['items' => [
                ['icon_key' => 'network', 'title' => 'Specialisti coordinati', 'description' => 'Le diverse competenze del centro possono condividere informazioni e orientare il percorso quando la situazione richiede più di una valutazione specialistica.'],
                ['icon_key' => 'microscope', 'title' => 'Diagnostica integrata', 'description' => 'Visite ed esami possono essere organizzati nello stesso centro, facilitando gli approfondimenti e riducendo passaggi inutili tra strutture differenti.'],
                ['icon_key' => 'heart', 'title' => 'Cura centrata su di te', 'description' => 'Il percorso parte dalle esigenze della persona e non dalla singola prestazione, con attenzione alla chiarezza, all’ascolto e alla continuità.'],
            ]], 'sort_order' => 2, 'is_active' => true],
            ['key' => 'integrated_workflow', 'title' => 'Quando competenze e diagnostica lavorano insieme', 'content' => 'Avere professionisti e strumenti nello stesso centro permette di costruire percorsi più leggibili e di collegare più facilmente valutazione clinica, approfondimenti diagnostici e controlli successivi.', 'extra_json' => ['image_path' => null, 'image_alt' => null], 'sort_order' => 3, 'is_active' => true],
            ['key' => 'continuity', 'title' => 'Un percorso che non riparte da zero', 'content' => 'Quando sono necessari nuovi controlli o competenze differenti, mantenere continuità nelle informazioni aiuta il paziente a orientarsi meglio tra le diverse fasi del percorso.', 'extra_json' => ['image_path' => null, 'image_alt' => null], 'sort_order' => 4, 'is_active' => true],
            ['key' => 'patient_experiences', 'title' => 'Le esperienze di chi si affida a Remedic', 'content' => 'Ascoltare chi ha già vissuto il centro aiuta a capire come viene percepita l’esperienza di cura.', 'extra_json' => ['eyebrow' => 'LA VOCE DEI PAZIENTI', 'disclaimer' => 'Le testimonianze mostrate sono contenuti dimostrativi in attesa di fonti verificate.', 'testimonials' => [
                ['source_type' => 'google', 'quote' => 'Ho trovato indicazioni chiare sui passaggi successivi e un punto di riferimento durante tutto il percorso.', 'author_name' => 'Paziente anonimo', 'author_label' => 'Paziente anonimo', 'avatar_text' => 'PA', 'is_active' => true, 'sort_order' => 0],
                ['source_type' => 'miodottore', 'quote' => 'La possibilità di svolgere visite e approfondimenti nello stesso centro ha reso l’organizzazione più semplice.', 'author_name' => 'Paziente anonimo', 'author_label' => 'Paziente anonimo', 'avatar_text' => 'PA', 'is_active' => true, 'sort_order' => 1],
                ['source_type' => 'google', 'quote' => 'Mi sono sentita ascoltata e accompagnata con attenzione, senza dover ricostruire ogni volta tutte le informazioni.', 'author_name' => 'Paziente anonimo', 'author_label' => 'Paziente anonimo', 'avatar_text' => 'PA', 'is_active' => true, 'sort_order' => 2],
            ]], 'sort_order' => 5, 'is_active' => true],
            ['key' => 'plus_health_protocol_cta', 'title' => 'Scopri il metodo che guida il percorso', 'content' => 'Professionalità, rapidità, accessibilità e umanità sono i quattro valori del Protocollo Più Salute.', 'extra_json' => ['link_label' => 'Scopri il Protocollo Più Salute', 'target_internal_key' => 'plus_health_protocol'], 'sort_order' => 6, 'is_active' => true],
            ['key' => 'orientation_cta', 'title' => 'Hai bisogno di orientarti?', 'content' => 'Contattaci per capire quale visita, esame o percorso può essere più adatto alla tua esigenza.', 'extra_json' => ['actions' => ['booking', 'contact']], 'sort_order' => 7, 'is_active' => true],
        ];
    }
}
