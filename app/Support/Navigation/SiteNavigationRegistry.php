<?php

namespace App\Support\Navigation;

final class SiteNavigationRegistry
{
    public const HEADER_KEYS = ['center_menu', 'medical_areas_menu', 'diagnostics', 'aesthetic_medicine', 'equipe', 'contact'];

    /** @var array<string, array{label: string, target?: string, type: string}> */
    public const HEADER = [
        'center_menu' => ['label' => 'Il centro', 'type' => 'mega_menu_trigger'],
        'medical_areas_menu' => ['label' => 'Aree mediche', 'type' => 'mega_menu_trigger'],
        'diagnostics' => ['label' => 'Diagnostica', 'target' => 'diagnostics_index', 'type' => 'semantic_link'],
        'aesthetic_medicine' => ['label' => 'Medicina estetica', 'target' => 'aesthetic_medicine_index', 'type' => 'semantic_link'],
        'equipe' => ['label' => 'Equipe', 'target' => 'equipe_index', 'type' => 'semantic_link'],
        'contact' => ['label' => 'Contatti', 'target' => 'contact', 'type' => 'semantic_link'],
    ];

    /** @var array<string, array{label: string, targets: list<string>}> */
    public const CENTER_GROUPS = [
        'know_remedic' => ['label' => 'CONOSCI REMEDIC', 'targets' => ['center', 'why_choose_us', 'plus_health_protocol']],
        'territory' => ['label' => 'REMEDIC E IL TERRITORIO', 'targets' => ['conventions_network', 'careers']],
        'prevention' => ['label' => 'PREVENZIONE', 'targets' => ['checkups_index']],
        'information_health' => ['label' => 'INFORMAZIONE E SALUTE', 'targets' => ['news_index', 'health_pills_index']],
    ];

    /** @var array<string, array{title: string, targets: list<string>}> */
    public const FOOTER_COLUMNS = [
        'center' => ['title' => 'IL CENTRO', 'targets' => ['center', 'why_choose_us', 'plus_health_protocol', 'conventions_network', 'careers']],
        'services' => ['title' => 'SERVIZI', 'targets' => ['medical_areas_index', 'diagnostics_index', 'aesthetic_medicine_index', 'checkups_index', 'equipe_index']],
        'information' => ['title' => 'ASSISTENZA', 'targets' => ['contact', 'news_index', 'health_pills_index']],
    ];

    /** @var array<string, string> */
    public const TARGETS = [
        'center' => 'Il centro', 'why_choose_us' => 'Perché sceglierci', 'plus_health_protocol' => 'Protocollo Più Salute',
        'contact' => 'Contatti', 'conventions_network' => 'Convenzioni e network', 'careers' => 'Lavora con noi',
        'privacy' => 'Privacy Policy', 'cookie_policy' => 'Cookie Policy', 'terms_of_service' => 'Termini di servizio',
        'medical_areas_index' => 'Aree mediche', 'equipe_index' => 'Equipe', 'checkups_index' => 'Check-up e prevenzione',
        'diagnostics_index' => 'Diagnostica', 'aesthetic_medicine_index' => 'Medicina estetica', 'news_index' => 'News e aggiornamenti',
        'health_pills_index' => 'Pillole di salute', 'booking' => 'Prenota ora', 'reserved_area' => 'Area riservata', 'cookie_preferences' => 'Gestisci preferenze cookie',
    ];

    public static function defaults(): array
    {
        return [
            'header' => array_map(static fn (string $key): array => ['key' => $key, 'is_active' => true, 'label' => null], self::HEADER_KEYS),
            'center_mega_menu' => [
                'groups' => collect(self::CENTER_GROUPS)->mapWithKeys(static fn (array $group, string $key): array => [$key => [
                    'key' => $key,
                    'items' => array_map(static fn (string $target): array => ['target' => $target, 'is_active' => true, 'label' => null, 'description' => null], $group['targets']),
                ]])->all(),
                'promo' => ['eyebrow' => 'ESPLORA', 'title' => 'Conosci Remedic', 'body' => null, 'cta_label' => 'Scopri il centro', 'cta_target' => 'center'],
            ],
            'medical_areas_mega_menu' => [
                'specialization_ids' => [],
                'promo' => ['eyebrow' => 'ESPLORA', 'title' => 'Tutte le aree mediche', 'body' => null, 'cta_label' => 'Scopri tutte le aree mediche'],
            ],
            'footer' => [
                'brand_description' => 'Centro medico multidisciplinare. Più specialità, un unico percorso di cura coordinato attorno a te.',
                'booking_label' => 'Prenota ora',
                'contact_visibility' => ['address' => true, 'phone' => true, 'email' => true, 'hours' => true],
                'legal_visibility' => ['privacy' => true, 'cookie_policy' => true, 'terms_of_service' => true, 'cookie_preferences' => true],
                'social_visibility' => [],
                'columns' => collect(self::FOOTER_COLUMNS)->mapWithKeys(static fn (array $column, string $key): array => [$key => [
                    'key' => $key,
                    'title' => $column['title'],
                    'items' => array_map(static fn (string $target): array => ['target' => $target, 'is_active' => true, 'label' => null], $column['targets']),
                ]])->all(),
            ],
        ];
    }

    public static function targetExists(string $target): bool
    {
        return array_key_exists($target, self::TARGETS);
    }
}
