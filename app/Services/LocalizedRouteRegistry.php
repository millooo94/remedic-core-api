<?php

namespace App\Services;

use App\Enums\SupportedLocale;

/** Canonical public paths. Italian intentionally has no language prefix. */
class LocalizedRouteRegistry
{
    private const PATHS = [
        'medical_areas' => ['it' => '/aree-mediche', 'en' => '/en/medical-areas', 'es' => '/es/areas-medicas', 'fr' => '/fr/specialites-medicales'],
        'team' => ['it' => '/equipe', 'en' => '/en/team', 'es' => '/es/equipo', 'fr' => '/fr/equipe'],
        'services' => ['it' => '/prestazioni', 'en' => '/en/services', 'es' => '/es/servicios', 'fr' => '/fr/prestations'],
        'checkups' => ['it' => '/check-up', 'en' => '/en/check-ups', 'es' => '/es/check-ups', 'fr' => '/fr/bilans-de-sante'],
        'diagnostics' => ['it' => '/diagnostica', 'en' => '/en/diagnostics', 'es' => '/es/diagnostico', 'fr' => '/fr/diagnostic'],
        'aesthetic_medicine' => ['it' => '/medicina-estetica', 'en' => '/en/aesthetic-medicine', 'es' => '/es/medicina-estetica', 'fr' => '/fr/medecine-esthetique'],
        'news' => ['it' => '/news', 'en' => '/en/news', 'es' => '/es/noticias', 'fr' => '/fr/actualites'],
        'health_tips' => ['it' => '/pillole-di-salute', 'en' => '/en/health-tips', 'es' => '/es/consejos-de-salud', 'fr' => '/fr/conseils-sante'],
    ];

    public function path(string $key, SupportedLocale $locale, ?string $slug = null): string
    {
        $base = self::PATHS[$key][$locale->value] ?? throw new \InvalidArgumentException("Unknown localized route [$key].");

        return $slug === null ? $base : $base.'/'.rawurlencode($slug);
    }

    public function homepage(SupportedLocale $locale): string
    {
        return $locale === SupportedLocale::IT ? '/' : '/'.$locale->value;
    }

    /** Public pages use their published localized slug below the locale prefix. */
    public function page(SupportedLocale $locale, string $slug): string
    {
        return $locale === SupportedLocale::IT
            ? '/'.rawurlencode($slug)
            : '/'.$locale->value.'/'.rawurlencode($slug);
    }
}
