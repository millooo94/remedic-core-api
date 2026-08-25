<?php

namespace App\Support\Pages;

use App\Models\Page;

/** Closed editorial contracts for typed institutional Pages. */
final class PageSectionRegistry
{
    public const CENTER_INTERNAL_KEY = 'center';

    public const WHY_CHOOSE_US_INTERNAL_KEY = 'why_choose_us';

    public const PLUS_HEALTH_PROTOCOL_INTERNAL_KEY = 'plus_health_protocol';

    public const CONTACT_INTERNAL_KEY = 'contact';

    public const CONVENTIONS_NETWORK_INTERNAL_KEY = 'conventions_network';

    public const CENTER_SECTION_KEYS = [
        'hero', 'intro', 'coordinated_care', 'continuity', 'why_remedic', 'plus_health_protocol', 'orientation_cta',
    ];

    public const WHY_CHOOSE_US_SECTION_KEYS = [
        'hero', 'model_overview', 'three_reasons', 'integrated_workflow', 'continuity', 'patient_experiences', 'plus_health_protocol_cta', 'orientation_cta',
    ];

    public const PLUS_HEALTH_PROTOCOL_SECTION_KEYS = [
        'hero', 'promise', 'four_pillars', 'care_path_overview', 'active_listening', 'personalized_care_plan', 'clinical_technology', 'patient_education', 'person_first', 'method_statement', 'orientation_cta',
    ];

    public const CONTACT_SECTION_KEYS = ['hero', 'location_and_contacts', 'orientation_cta'];

    public const CONVENTIONS_NETWORK_SECTION_KEYS = ['hero', 'access_process', 'conventions_catalog', 'contact_cta'];

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
        self::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY => [
            'hero' => ['label' => 'Hero', 'summary' => 'Introduzione al Protocollo Più Salute.', 'editor' => 'protocol-hero', 'default_sort_order' => 0, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'promise' => ['label' => 'Più Salute è una promessa', 'summary' => 'Metodo e quattro valori editoriali fissi.', 'editor' => 'protocol-promise', 'default_sort_order' => 1, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'four_pillars' => ['label' => 'I quattro pilastri', 'summary' => 'Copy dei pannelli, senza configurazione della ruota.', 'editor' => 'protocol-four-pillars', 'default_sort_order' => 2, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'care_path_overview' => ['label' => 'Dai valori al percorso', 'summary' => 'Quattro passaggi fissi del percorso.', 'editor' => 'protocol-care-path-overview', 'default_sort_order' => 3, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'active_listening' => ['label' => 'Ascolto attivo', 'summary' => 'Approfondimento con immagine.', 'editor' => 'protocol-image-text', 'default_sort_order' => 4, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'personalized_care_plan' => ['label' => 'Piano di cura personalizzato', 'summary' => 'Approfondimento con immagine.', 'editor' => 'protocol-image-text', 'default_sort_order' => 5, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'clinical_technology' => ['label' => 'Tecnologia e valutazione clinica', 'summary' => 'Approfondimento con immagine.', 'editor' => 'protocol-image-text', 'default_sort_order' => 6, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'patient_education' => ['label' => 'Educazione del paziente', 'summary' => 'Contenuto con callout editoriale.', 'editor' => 'protocol-patient-education', 'default_sort_order' => 7, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'person_first' => ['label' => 'La persona al centro', 'summary' => 'Punti ordinabili con immagine.', 'editor' => 'protocol-person-first', 'default_sort_order' => 8, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'method_statement' => ['label' => 'Più Salute', 'summary' => 'Dichiarazione del metodo.', 'editor' => 'protocol-method-statement', 'default_sort_order' => 9, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'orientation_cta' => ['label' => 'Orientamento finale', 'summary' => 'Invito con azioni globali del sito.', 'editor' => 'protocol-orientation-cta', 'default_sort_order' => 10, 'capabilities' => ['edit', 'toggle', 'reorder'], 'actions' => ['booking', 'contact']],
        ],
        self::CONTACT_INTERNAL_KEY => [
            'hero' => ['label' => 'Hero', 'summary' => 'Introduzione e immagine editoriale della pagina.', 'editor' => 'contact-hero', 'default_sort_order' => 0, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'location_and_contacts' => ['label' => 'Informazioni e sede', 'summary' => 'Copy editoriale e dati derivati dal Centro.', 'editor' => 'contact-location-and-contacts', 'default_sort_order' => 1, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'orientation_cta' => ['label' => 'Orientamento finale', 'summary' => 'Invito con azioni globali del sito.', 'editor' => 'contact-orientation-cta', 'default_sort_order' => 2, 'capabilities' => ['edit', 'toggle', 'reorder'], 'actions' => ['booking', 'contact']],
        ],
        self::CONVENTIONS_NETWORK_INTERNAL_KEY => [
            'hero' => ['label' => 'Hero', 'summary' => 'Introduzione e immagine editoriale della pagina.', 'editor' => 'conventions-hero', 'default_sort_order' => 0, 'capabilities' => ['edit', 'toggle', 'reorder', 'media'], 'media_slot' => 'image'],
            'access_process' => ['label' => 'Accesso alle prestazioni', 'summary' => 'Tre passaggi fissi, con copy editoriale.', 'editor' => 'conventions-access-process', 'default_sort_order' => 1, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'conventions_catalog' => ['label' => 'Tutte le convenzioni', 'summary' => 'Copy editoriale e catalogo derivato dalla Gestione.', 'editor' => 'conventions-catalog', 'default_sort_order' => 2, 'capabilities' => ['edit', 'toggle', 'reorder']],
            'contact_cta' => ['label' => 'Contatto finale', 'summary' => 'CTA globale per contattare il centro.', 'editor' => 'conventions-contact-cta', 'default_sort_order' => 3, 'capabilities' => ['edit', 'toggle', 'reorder'], 'actions' => ['contact']],
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
        if (! in_array($internalKey, [self::CENTER_INTERNAL_KEY, self::WHY_CHOOSE_US_INTERNAL_KEY, self::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY, self::CONTACT_INTERNAL_KEY, self::CONVENTIONS_NETWORK_INTERNAL_KEY], true)) {
            return [];
        }

        $existingKeys = $page->sections()->pluck('key')->all();

        $defaults = match ($internalKey) {
            self::CENTER_INTERNAL_KEY => self::centerDefaults(),
            self::WHY_CHOOSE_US_INTERNAL_KEY => self::whyChooseUsDefaults(),
            self::CONTACT_INTERNAL_KEY => self::contactDefaults(),
            self::CONVENTIONS_NETWORK_INTERNAL_KEY => self::conventionsNetworkDefaults(),
            default => self::plusHealthProtocolDefaults(),
        };

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

    /** @return list<array{key: string, title: string, content: string, extra_json: array<string, mixed>, sort_order: int, is_active: bool}> */
    private static function plusHealthProtocolDefaults(): array
    {
        return [
            ['key' => 'hero', 'title' => 'Quattro valori, un unico modo di prenderci cura di te', 'content' => 'Professionalità, rapidità, accessibilità e umanità guidano un metodo pensato per rendere il percorso di cura più chiaro, coordinato e vicino alla persona.', 'extra_json' => ['eyebrow' => 'PROTOCOLLO PIÙ SALUTE', 'image_path' => null, 'image_alt' => null], 'sort_order' => 0, 'is_active' => true],
            ['key' => 'promise', 'title' => 'Più Salute è una promessa', 'content' => 'Più Salute nasce dall’idea che prendersi cura di una persona significhi guardare oltre la singola prestazione. Competenze, tempi, accessibilità e relazione devono lavorare insieme lungo lo stesso percorso.', 'extra_json' => ['eyebrow' => 'IL NOSTRO METODO', 'values' => self::protocolValues()], 'sort_order' => 1, 'is_active' => true],
            ['key' => 'four_pillars', 'title' => 'Quattro pilastri, un unico modo di prenderci cura di te', 'content' => 'Non una semplice visita, ma un metodo: quattro valori che si integrano in un unico percorso, pensato per prendersi cura di te nel tempo.', 'extra_json' => ['eyebrow' => 'I QUATTRO PILASTRI', 'pillars' => self::protocolPillars()], 'sort_order' => 2, 'is_active' => true],
            ['key' => 'care_path_overview', 'title' => 'Dai valori al percorso di cura', 'content' => 'I quattro pilastri descrivono il modo in cui Remedic vuole prendersi cura delle persone. Il Protocollo Più Salute traduce questi valori in passaggi concreti lungo il percorso.', 'extra_json' => ['items' => self::carePathItems()], 'sort_order' => 3, 'is_active' => true],
            ['key' => 'active_listening', 'title' => 'Ascolto attivo', 'content' => 'Il percorso parte dalla comprensione delle esigenze, della storia clinica, delle preoccupazioni e degli obiettivi della persona. L’ascolto permette di orientare in modo più chiaro i passaggi successivi.', 'extra_json' => ['image_path' => null, 'image_alt' => null], 'sort_order' => 4, 'is_active' => true],
            ['key' => 'personalized_care_plan', 'title' => 'Piano di cura personalizzato', 'content' => 'Le informazioni raccolte aiutano a definire un percorso coerente con le necessità della persona, organizzando priorità, visite, eventuali approfondimenti e controlli.', 'extra_json' => ['image_path' => null, 'image_alt' => null], 'sort_order' => 5, 'is_active' => true],
            ['key' => 'clinical_technology', 'title' => 'Tecnologia al servizio della valutazione clinica', 'content' => 'Strumenti diagnostici e tecnologie supportano i professionisti negli approfondimenti e aiutano a mantenere continuità nelle informazioni lungo il percorso.', 'extra_json' => ['image_path' => null, 'image_alt' => null], 'sort_order' => 6, 'is_active' => true],
            ['key' => 'patient_education', 'title' => 'Conoscere aiuta a prendersi cura di sé', 'content' => 'Informazioni comprensibili e indicazioni chiare aiutano la persona a conoscere meglio la propria salute, seguire il percorso concordato e adottare comportamenti di prevenzione più consapevoli.', 'extra_json' => ['callout_eyebrow' => 'EDUCAZIONE DEL PAZIENTE', 'callout_body' => 'Comprendere il perché di una visita, di un controllo o di un’indicazione aiuta a partecipare con maggiore consapevolezza al proprio percorso.'], 'sort_order' => 7, 'is_active' => true],
            ['key' => 'person_first', 'title' => 'La persona al centro, non la singola prestazione', 'content' => 'Ogni percorso parte da una persona con esigenze, domande e tempi diversi. Per questo il Protocollo Più Salute mette in relazione competenza, ascolto, strumenti e continuità.', 'extra_json' => ['eyebrow' => 'IL PUNTO DI PARTENZA', 'image_path' => null, 'image_alt' => null, 'items' => [['title' => 'Ascoltare', 'description' => 'Comprendere prima di orientare.'], ['title' => 'Coordinare', 'description' => 'Mettere in relazione competenze e informazioni quando serve.'], ['title' => 'Accompagnare', 'description' => 'Dare chiarezza ai passaggi successivi.']]], 'sort_order' => 8, 'is_active' => true],
            ['key' => 'method_statement', 'title' => 'Quattro valori. Un metodo. Una persona al centro.', 'content' => 'Professionalità, rapidità, accessibilità e umanità guidano il modo in cui Remedic costruisce il percorso. Il Protocollo Più Salute dà a questi valori una struttura concreta.', 'extra_json' => ['eyebrow' => 'PIÙ SALUTE'], 'sort_order' => 9, 'is_active' => true],
            ['key' => 'orientation_cta', 'title' => 'Parliamo del tuo percorso', 'content' => 'Contattaci se hai bisogno di capire quale visita, esame o percorso può essere più adatto alla tua esigenza.', 'extra_json' => ['actions' => ['booking', 'contact']], 'sort_order' => 10, 'is_active' => true],
        ];
    }

    /** @return list<array{key: string, title: string, content: string, extra_json: array<string, mixed>, sort_order: int, is_active: bool}> */
    private static function contactDefaults(): array
    {
        return [
            ['key' => 'hero', 'title' => 'Contatti', 'content' => 'Tutte le informazioni per contattare Remedic e raggiungere il centro.', 'extra_json' => ['eyebrow' => 'CONTATTI', 'image_path' => null, 'image_alt' => null], 'sort_order' => 0, 'is_active' => true],
            ['key' => 'location_and_contacts', 'title' => 'Informazioni e sede', 'content' => 'Consulta i recapiti e gli orari del centro, oppure scegli l’azione più utile per te.', 'extra_json' => [], 'sort_order' => 1, 'is_active' => true],
            ['key' => 'orientation_cta', 'title' => 'Cerchi una visita o un esame?', 'content' => 'Prenota online oppure contattaci per ricevere assistenza.', 'extra_json' => ['actions' => ['booking', 'contact']], 'sort_order' => 2, 'is_active' => true],
        ];
    }

    /** @return list<array{key: string, title: string, content: string, extra_json: array<string, mixed>, sort_order: int, is_active: bool}> */
    private static function conventionsNetworkDefaults(): array
    {
        return [
            ['key' => 'hero', 'title' => 'Convenzioni e network', 'content' => 'Remedic collabora con fondi, assicurazioni, network, enti e realtà convenzionate per facilitare l’accesso alle prestazioni e semplificare la gestione delle pratiche.', 'extra_json' => ['eyebrow' => 'CONVENZIONI', 'image_path' => null, 'image_alt' => null], 'sort_order' => 0, 'is_active' => true],
            ['key' => 'access_process', 'title' => 'Più semplice accedere alle prestazioni', 'content' => 'Ogni convenzione segue regole specifiche. Il centro ti aiuta a comprendere i passaggi utili prima della prenotazione.', 'extra_json' => ['items' => self::conventionAccessProcessItems()], 'sort_order' => 1, 'is_active' => true],
            ['key' => 'conventions_catalog', 'title' => 'Tutte le convenzioni', 'content' => 'Consulta i fondi, network, assicurazioni, enti e realtà convenzionate con Remedic.', 'extra_json' => [], 'sort_order' => 2, 'is_active' => true],
            ['key' => 'contact_cta', 'title' => 'Non trovi la tua convenzione?', 'content' => 'Verifichiamo insieme la tua convenzione.', 'extra_json' => ['actions' => ['contact']], 'sort_order' => 3, 'is_active' => true],
        ];
    }

    /** @return list<array{semantic_key: string, title: string, description: string, icon_key: string}> */
    private static function conventionAccessProcessItems(): array
    {
        return [
            ['semantic_key' => 'direct_booking', 'title' => 'Prenotazione diretta', 'description' => 'Remedic può supportare la prenotazione attraverso il fondo, network, ente o convenzione applicabile.', 'icon_key' => 'calendar'],
            ['semantic_key' => 'practice_management', 'title' => 'Gestione della pratica', 'description' => 'Il centro supporta il paziente nella gestione delle informazioni e della documentazione richiesta dal circuito convenzionato.', 'icon_key' => 'clipboard'],
            ['semantic_key' => 'agreement_conditions', 'title' => 'Condizioni della convenzione', 'description' => 'Coperture, franchigie, rimborsi o eventuale anticipazione dipendono dalle condizioni previste dallo specifico accordo o piano.', 'icon_key' => 'info'],
        ];
    }

    /** @return list<array{semantic_key: string, label: string, description: string}> */
    private static function protocolValues(): array
    {
        return [
            ['semantic_key' => 'rapidity', 'label' => 'RAPIDITÀ', 'description' => 'Orientamento rapido, comunicazioni coordinate e un percorso che evita passaggi inutili.'],
            ['semantic_key' => 'professionalism', 'label' => 'PROFESSIONALITÀ', 'description' => 'Specialisti qualificati, standard clinici e strumenti diagnostici integrati.'],
            ['semantic_key' => 'accessibility', 'label' => 'ACCESSIBILITÀ', 'description' => 'Informazioni comprensibili, convenzioni e percorsi costruiti sulle necessità reali del paziente.'],
            ['semantic_key' => 'humanity', 'label' => 'UMANITÀ', 'description' => 'Ascolto, attenzione e continuità nella relazione con il paziente.'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function protocolPillars(): array
    {
        return [
            ['semantic_key' => 'rapidity', 'label' => 'Rapidità', 'detail_eyebrow' => null, 'detail_title' => null, 'detail_description' => 'Orientamento rapido, comunicazioni coordinate e un percorso che evita passaggi inutili.', 'bullets' => []],
            ['semantic_key' => 'professionalism', 'label' => 'Professionalità', 'detail_eyebrow' => null, 'detail_title' => 'Competenze che lavorano insieme', 'detail_description' => 'Specialisti qualificati, standard clinici e strumenti diagnostici integrati.', 'bullets' => ['Specialisti qualificati e standard clinici', 'Strumenti diagnostici integrati']],
            ['semantic_key' => 'accessibility', 'label' => 'Accessibilità', 'detail_eyebrow' => null, 'detail_title' => null, 'detail_description' => 'Informazioni comprensibili, convenzioni e percorsi costruiti sulle necessità reali del paziente.', 'bullets' => []],
            ['semantic_key' => 'humanity', 'label' => 'Umanità', 'detail_eyebrow' => null, 'detail_title' => null, 'detail_description' => 'Ascolto, attenzione e continuità nella relazione con il paziente.', 'bullets' => []],
        ];
    }

    /** @return list<array{semantic_key: string, title: string, description: string, icon_key: string}> */
    private static function carePathItems(): array
    {
        return [
            ['semantic_key' => 'active_listening', 'title' => 'Ascolto attivo', 'description' => 'Il percorso parte dalla comprensione delle esigenze, della storia clinica, delle preoccupazioni e degli obiettivi della persona.', 'icon_key' => 'message'],
            ['semantic_key' => 'personalized_care_plan', 'title' => 'Piano di cura personalizzato', 'description' => 'Le informazioni raccolte aiutano a definire priorità, visite, eventuali approfondimenti e controlli coerenti con le necessità della persona.', 'icon_key' => 'clipboard'],
            ['semantic_key' => 'clinical_technology', 'title' => 'Integrazione tecnologica', 'description' => 'Strumenti diagnostici e tecnologie supportano i professionisti e la continuità delle informazioni lungo il percorso.', 'icon_key' => 'microscope'],
            ['semantic_key' => 'patient_education', 'title' => 'Educazione del paziente', 'description' => 'Informazioni comprensibili e indicazioni chiare aiutano la persona a conoscere meglio la propria salute e il percorso concordato.', 'icon_key' => 'info'],
        ];
    }
}
