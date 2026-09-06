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

    /** @var array<string, array{label: string, items: list<string>}> */
    public const CENTER_GROUPS = [
        'know_remedic' => ['label' => 'CONOSCI REMEDIC', 'items' => ['center', 'why_choose_us', 'plus_health_protocol']],
        'territory' => ['label' => 'REMEDIC E IL TERRITORIO', 'items' => ['conventions_network', 'careers', 'territory_slot_3']],
        'prevention' => ['label' => 'PREVENZIONE', 'items' => ['checkups_index', 'prevention_slot_2', 'prevention_slot_3']],
        'information_health' => ['label' => 'INFORMAZIONE E SALUTE', 'items' => ['news_index', 'health_pills_index', 'information_health_slot_3']],
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
        'health_pills_index' => 'Pillole di salute', 'booking' => 'Prenota ora', 'phone' => 'Chiama', 'whatsapp' => 'WhatsApp', 'map' => 'Apri mappa', 'email' => 'Invia email', 'newsletter_subscription' => 'Iscriviti alla newsletter', 'external_url' => 'Link esterno', 'reserved_area' => 'Area riservata', 'cookie_preferences' => 'Gestisci preferenze cookie',
        'privacy_guarantor' => 'Garante per la protezione dei dati personali',
        'vercel_privacy' => 'Informativa privacy Vercel',
    ];

    /** @var array<string, string> */
    public const ACTIONS = [
        'booking' => 'booking',
        'phone' => 'phone',
        'whatsapp' => 'whatsapp',
        'map' => 'map',
        'email' => 'email',
        'newsletter_subscription' => 'newsletter_subscription',
        'external_url' => 'external_url',
        'reserved_area' => 'reserved_area',
        'cookie_preferences' => 'cookie_preferences',
    ];

    /** @var array<string, array{href: string, label: string}> */
    public const FIXED_EXTERNAL_TARGETS = [
        'privacy_guarantor' => ['label' => 'Garante per la protezione dei dati personali', 'href' => 'https://www.garanteprivacy.it/'],
        'vercel_privacy' => ['label' => 'Informativa privacy Vercel', 'href' => 'https://vercel.com/docs/analytics/privacy-policy'],
    ];

    /** @return array{href: string, label: string}|null */
    public static function fixedExternalTarget(string $target): ?array
    {
        return self::FIXED_EXTERNAL_TARGETS[$target] ?? null;
    }

    public static function defaults(): array
    {
        return [
            'header' => array_map(static fn (string $key): array => ['key' => $key, 'is_active' => true, 'label' => null], self::HEADER_KEYS),
            'center_mega_menu' => [
                'groups' => collect(self::CENTER_GROUPS)->map(static fn (array $group, string $key): array => [
                    'key' => $key,
                    'label' => $group['label'],
                    'is_active' => true,
                    'items' => array_map(static fn (string $key): array => self::centerItemDefault($key), $group['items']),
                ])->values()->all(),
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

    /** @return array{key: string, target: ?string, is_active: bool, label: null, description: null, icon_path: null, link_type: string, external_url: null} */
    private static function centerItemDefault(string $key): array
    {
        $targetExists = self::targetExists($key);

        return [
            'key' => $key,
            'target' => $targetExists ? $key : null,
            'is_active' => $targetExists,
            'label' => null,
            'description' => null,
            'icon_path' => null,
            'link_type' => $targetExists ? 'internal' : 'none',
            'external_url' => null,
        ];
    }
}
