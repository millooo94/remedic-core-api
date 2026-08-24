<?php

namespace App\Support\Pages;

use App\Models\Page;

/** Closed editorial contracts for typed institutional Pages. */
final class PageSectionRegistry
{
    public const CENTER_INTERNAL_KEY = 'center';

    public const CENTER_SECTION_KEYS = [
        'hero', 'intro', 'coordinated_care', 'continuity', 'why_remedic', 'plus_health_protocol', 'orientation_cta',
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
        if ((string) $page->internal_key !== self::CENTER_INTERNAL_KEY) {
            return [];
        }

        $existingKeys = $page->sections()->pluck('key')->all();

        return array_values(array_filter(self::centerDefaults(), fn (array $section): bool => ! in_array($section['key'], $existingKeys, true)));
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
}
