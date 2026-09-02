<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use App\Models\Page;
use App\Models\SiteIndexPage;
use App\Models\SiteNavigation;
use App\Models\SiteSetting;
use App\Models\SpecializationWebProfile;
use App\Support\Media\PublicMediaUrl;
use App\Support\Navigation\SiteNavigationRegistry;
use Illuminate\Http\Request;

class SiteNavigationProjectionService
{
    public function __construct(private readonly ContactCenterDataResolver $centerData, private readonly MedicalAreaPublicService $areas, private readonly ConsentConfigurationInitializer $consentConfiguration, private readonly PublicLocaleResolver $locales, private readonly LocalizedContentResolver $localized, private readonly LocalizedRouteRegistry $routes) {}

    /** @return array<string, mixed> */
    public function admin(SiteNavigation $navigation, Request $request): array
    {
        $configuration = $this->configuration($navigation);
        $configuration['center_mega_menu']['sections'] = collect($configuration['center_mega_menu']['sections'] ?? [])
            ->map(fn (array $section): array => [...$section, 'icon_url' => PublicMediaUrl::fromPublicDisk($section['icon_path'] ?? null, $request)])
            ->map(function (array $section): array {
                unset($section['icon_path']);

                return $section;
            })->all();

        return [
            'id' => $navigation->getKey(),
            'configuration' => $configuration,
            'targets' => $this->targets(),
            'media' => ['center_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->center_mega_menu_promo_image_path, $request), 'medical_areas_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->medical_areas_mega_menu_promo_image_path, $request)],
            'area_candidates' => SpecializationWebProfile::query()->with('specialization')->orderBy('specialization_id')->get()->map(fn (SpecializationWebProfile $profile): array => ['specialization_id' => $profile->specialization_id, 'name' => $profile->specialization->name, 'icon_url' => PublicMediaUrl::fromPublicDisk($profile->specialization->icon_path, $request), 'public_slug' => $profile->slug, 'short_description' => $profile->specialization->short_description, 'publication_state' => $profile->isEffectivelyVisible() ? 'published' : 'not_public'])->all(),
            'center_location' => $this->center(),
        ];
    }

    /** @return array<string, mixed> */
    public function public(SiteNavigation $navigation, Request $request): array
    {
        $locale = $this->locales->resolve($request);
        $configuration = $this->configuration($navigation);
        $centerMenu = $this->publicCenterMenu($configuration['center_mega_menu'], $navigation, $request, $locale);
        $areasMenu = $this->publicAreasMenu($configuration['medical_areas_mega_menu'], $navigation, $request, $locale);
        $items = [];

        foreach ($configuration['header'] as $item) {
            if (! $item['is_active']) {
                continue;
            }

            $definition = SiteNavigationRegistry::HEADER[$item['key']];
            if ($item['key'] === 'center_menu') {
                if (($centerMenu['groups'] ?? []) === [] && empty($centerMenu['promo'])) {
                    continue;
                }
                $items[] = ['key' => $item['key'], 'type' => $definition['type'], 'label' => $item['label'] ?: $definition['label'], 'menu' => $centerMenu];

                continue;
            }
            if ($item['key'] === 'medical_areas_menu') {
                if ($areasMenu['items'] === [] && empty($areasMenu['promo'])) {
                    continue;
                }
                $items[] = ['key' => $item['key'], 'type' => $definition['type'], 'label' => $item['label'] ?: $definition['label'], 'menu' => $areasMenu];

                continue;
            }

            $href = ($item['link_type'] ?? 'internal') === 'external'
                ? (filled($item['external_url'] ?? null) ? (string) $item['external_url'] : null)
                : $this->target((string) ($item['target'] ?? $definition['target']), $locale)['href'];
            if ($href === null) {
                continue;
            }
            $items[] = ['key' => $item['key'], 'type' => $definition['type'], 'label' => $item['label'] ?: $definition['label'], 'href' => $href];
        }

        return [
            'header' => [
                'items' => $items,
                'reserved_area' => ['action' => 'reserved_area', 'label' => SiteNavigationRegistry::TARGETS['reserved_area']],
                'booking' => ['action' => 'booking', 'label' => SiteNavigationRegistry::TARGETS['booking']],
            ],
            'footer' => $this->publicFooter($configuration['footer'], $locale),
        ];
    }

    /** @return array<string, mixed> */
    public function configuration(SiteNavigation $navigation): array
    {
        $defaults = SiteNavigationRegistry::defaults();
        $saved = $navigation->configuration ?? [];

        return [
            'header' => $this->orderedHeader($saved['header'] ?? $defaults['header']),
            'center_mega_menu' => $this->centerMenuConfiguration($saved['center_mega_menu'] ?? $defaults['center_mega_menu']),
            'medical_areas_mega_menu' => $this->areasMenuConfiguration($saved['medical_areas_mega_menu'] ?? $defaults['medical_areas_mega_menu']),
            'footer' => $this->footerConfiguration($saved['footer'] ?? $defaults['footer']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function targets(): array
    {
        return collect(SiteNavigationRegistry::TARGETS)
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, ...$this->target($key)])
            ->values()->all();
    }

    /** @return array{href: ?string, publication_state: string, is_public: bool, action?: string} */
    public function target(string $key, ?SupportedLocale $locale = null): array
    {
        $locale ??= SupportedLocale::IT;
        if ($key === 'cookie_preferences') {
            return ['href' => null, 'publication_state' => 'action', 'is_public' => (bool) $this->consentConfiguration->initialize()->is_enabled, 'action' => 'cookie_preferences'];
        }
        if (in_array($key, ['booking', 'reserved_area'], true)) {
            return ['href' => null, 'publication_state' => 'action', 'is_public' => true, 'action' => $key];
        }
        if (str_ends_with($key, '_index')) {
            $page = SiteIndexPage::query()->with('translations')->where('internal_key', $key)->first();
            $translation = $page?->translations->firstWhere('locale', $locale);
            $public = ($page?->isPubliclyAvailable() ?? false) && ($locale === SupportedLocale::IT || $translation?->isPubliclyAvailable());

            return ['href' => $public ? $this->routes->path(match ($key) {
                'medical_areas_index' => 'medical_areas',
                'equipe_index' => 'team',
                'checkups_index' => 'checkups',
                'diagnostics_index' => 'diagnostics',
                'aesthetic_medicine_index' => 'aesthetic_medicine',
                'news_index' => 'news',
                'health_pills_index' => 'health_tips',
            }, $locale) : null, 'publication_state' => $page?->publicationState()->value ?? 'missing', 'is_public' => $public];
        }

        $page = Page::query()->with('translations')->where('internal_key', $key === 'cookie_policy' ? 'cookie-policy' : $key)->first();
        $translation = $page?->translations->firstWhere('locale', $locale);
        $public = ($page?->isPubliclyAvailable() ?? false) && ($locale === SupportedLocale::IT || $translation?->isPubliclyAvailable());
        $slug = $locale === SupportedLocale::IT ? $page?->slug : $translation?->slug;

        return ['href' => $public && $slug ? $this->routes->page($locale, $slug) : null, 'publication_state' => $page?->publicationState()->value ?? 'missing', 'is_public' => $public];
    }

    /** @param array<string, mixed> $menu @return array<string, mixed> */
    private function publicCenterMenu(array $menu, SiteNavigation $navigation, Request $request, SupportedLocale $locale): array
    {
        $groups = [];
        if (($menu['sections'] ?? []) !== []) {
            foreach ($menu['sections'] as $section) {
                $href = ($section['link_type'] ?? 'internal') === 'external'
                    ? ($section['external_url'] ?? null)
                    : $this->target((string) ($section['target'] ?? ''), $locale)['href'];
                if (! filled($href)) {
                    continue;
                }
                $groups[] = ['key' => $section['key'], 'label' => $section['label'], 'items' => [[
                    'target' => $section['target'] ?? null,
                    'label' => $section['label'],
                    'description' => $section['subtitle'],
                    'href' => $href,
                    'icon_url' => PublicMediaUrl::fromPublicDisk($section['icon_path'] ?? null, $request),
                ]]];
            }
        } else {
            foreach ($menu['groups'] as $key => $group) {
                $items = [];
                foreach ($group['items'] as $item) {
                    if (! $item['is_active']) {
                        continue;
                    }
                    $target = $this->target($item['target'], $locale);
                    if ($target['href'] === null) {
                        continue;
                    }
                    $items[] = ['target' => $item['target'], 'label' => $item['label'] ?: SiteNavigationRegistry::TARGETS[$item['target']], 'description' => $item['description'], 'href' => $target['href']];
                }
                if ($items !== []) {
                    $groups[] = ['key' => $key, 'label' => SiteNavigationRegistry::CENTER_GROUPS[$key]['label'], 'items' => $items];
                }
            }
        }
        $promo = $menu['promo'];
        $href = ($promo['cta_link_type'] ?? 'internal') === 'external'
            ? ($promo['cta_external_url'] ?? null)
            : $this->target((string) ($promo['cta_target'] ?? ''), $locale)['href'];
        if ($href === null) {
            $promo['cta_label'] = null;
            $promo['cta_target'] = null;
        } else {
            $promo['href'] = $href;
        }
        $promo['image_url'] = PublicMediaUrl::fromPublicDisk($navigation->center_mega_menu_promo_image_path, $request);
        $location = $this->centerData->resolve(SiteSetting::current());

        return ['groups' => $groups, 'promo' => $promo, 'location' => ['address' => $location['address'], 'parking' => $location['parking'], 'directions' => $location['directions_href'] ? ['label' => 'Indicazioni stradali', 'href' => $location['directions_href']] : null]];
    }

    /** @param mixed $items @return list<array{key: string, is_active: bool, label: ?string}> */
    private function orderedHeader(mixed $items): array
    {
        $seen = [];
        $ordered = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $key = $item['key'] ?? null;
            if (! is_string($key) || ! in_array($key, SiteNavigationRegistry::HEADER_KEYS, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ordered[] = $key;
        }
        $ordered = [...$ordered, ...array_values(array_filter(SiteNavigationRegistry::HEADER_KEYS, static fn (string $key): bool => ! isset($seen[$key])))];
        $byKey = collect(is_array($items) ? $items : [])->keyBy('key');

        return collect($ordered)->map(static function (string $key) use ($byKey): array {
            $item = $byKey->get($key, []);

            return ['key' => $key, 'is_active' => (bool) ($item['is_active'] ?? true), 'label' => filled($item['label'] ?? null) ? (string) $item['label'] : null, 'link_type' => ($item['link_type'] ?? 'internal') === 'external' ? 'external' : 'internal', 'target' => isset($item['target']) ? (string) $item['target'] : (SiteNavigationRegistry::HEADER[$key]['target'] ?? null), 'external_url' => filled($item['external_url'] ?? null) ? (string) $item['external_url'] : null];
        })->all();
    }

    /** @param mixed $menu @return array<string, mixed> */
    private function centerMenuConfiguration(mixed $menu): array
    {
        $menu = is_array($menu) ? $menu : [];
        $groups = [];
        foreach (SiteNavigationRegistry::CENTER_GROUPS as $key => $definition) {
            $saved = is_array($menu['groups'][$key]['items'] ?? null) ? $menu['groups'][$key]['items'] : [];
            $byTarget = collect($saved)->keyBy('target');
            $orderedTargets = [];
            foreach ($saved as $item) {
                $target = $item['target'] ?? null;
                if (is_string($target) && in_array($target, $definition['targets'], true) && ! in_array($target, $orderedTargets, true)) {
                    $orderedTargets[] = $target;
                }
            }
            $orderedTargets = [...$orderedTargets, ...array_values(array_filter($definition['targets'], static fn (string $target): bool => ! in_array($target, $orderedTargets, true)))];
            $groups[$key] = ['key' => $key, 'items' => collect($orderedTargets)->map(static function (string $target) use ($byTarget): array {
                $item = $byTarget->get($target, []);

                return ['target' => $target, 'is_active' => (bool) ($item['is_active'] ?? true), 'label' => filled($item['label'] ?? null) ? (string) $item['label'] : null, 'description' => filled($item['description'] ?? null) ? (string) $item['description'] : null];
            })->all()];
        }
        $promo = is_array($menu['promo'] ?? null) ? $menu['promo'] : [];

        $sections = collect($menu['sections'] ?? [])->filter(fn ($section): bool => is_array($section))->map(fn (array $section): array => ['key' => (string) ($section['key'] ?? ''), 'label' => (string) ($section['label'] ?? ''), 'subtitle' => filled($section['subtitle'] ?? null) ? (string) $section['subtitle'] : null, 'icon_path' => filled($section['icon_path'] ?? null) ? (string) $section['icon_path'] : null, 'link_type' => ($section['link_type'] ?? 'internal') === 'external' ? 'external' : 'internal', 'target' => filled($section['target'] ?? null) ? (string) $section['target'] : null, 'external_url' => filled($section['external_url'] ?? null) ? (string) $section['external_url'] : null])->values()->all();
        if ($sections === []) {
            $sections = [
                ['key' => 'know_remedic', 'label' => 'Conosci Remedic', 'subtitle' => null, 'icon_path' => null, 'link_type' => 'internal', 'target' => 'center', 'external_url' => null],
                ['key' => 'territory', 'label' => 'Remedic e il territorio', 'subtitle' => null, 'icon_path' => null, 'link_type' => 'internal', 'target' => 'conventions_network', 'external_url' => null],
                ['key' => 'prevention', 'label' => 'Prevenzione', 'subtitle' => null, 'icon_path' => null, 'link_type' => 'internal', 'target' => 'checkups_index', 'external_url' => null],
                ['key' => 'information_health', 'label' => 'Informazione e salute', 'subtitle' => null, 'icon_path' => null, 'link_type' => 'internal', 'target' => 'news_index', 'external_url' => null],
            ];
        }

        return ['groups' => $groups, 'sections' => $sections, 'promo' => ['eyebrow' => (string) ($promo['eyebrow'] ?? 'ESPLORA'), 'title' => (string) ($promo['title'] ?? 'Conosci Remedic'), 'body' => filled($promo['body'] ?? null) ? (string) $promo['body'] : null, 'cta_label' => (string) ($promo['cta_label'] ?? 'Scopri il centro'), 'cta_target' => filled($promo['cta_target'] ?? null) ? (string) $promo['cta_target'] : 'center', 'cta_link_type' => ($promo['cta_link_type'] ?? 'internal') === 'external' ? 'external' : 'internal', 'cta_external_url' => filled($promo['cta_external_url'] ?? null) ? (string) $promo['cta_external_url'] : null]];
    }

    /** @param array<string, mixed> $menu @return array<string, mixed> */
    private function publicAreasMenu(array $menu, SiteNavigation $navigation, Request $request, SupportedLocale $locale): array
    {
        $profiles = $this->areas->query()->whereIn('specialization_id', $menu['specialization_ids'])->get()->keyBy('specialization_id');
        $items = collect($menu['specialization_ids'])->map(function (int $id) use ($profiles, $request): ?array {
            $profile = $profiles->get($id);
            if (! $profile instanceof SpecializationWebProfile) {
                return null;
            }
            $item = $this->areas->listItem($profile, $request);

            return ['public_slug' => $item['slug'], 'href' => $item['href'], 'name' => $item['name'], 'icon_url' => $item['icon_url'], 'short_description' => $item['short_description']];
        })->filter()->values()->all();
        $target = ($menu['promo']['cta_link_type'] ?? 'internal') === 'external'
            ? ['href' => $menu['promo']['cta_external_url'] ?? null]
            : $this->target((string) ($menu['promo']['cta_target'] ?? 'medical_areas_index'), $locale);
        $promo = $target['href'] === null ? null : [...$menu['promo'], 'href' => $target['href']];
        if ($promo !== null) {
            $promo['image_url'] = PublicMediaUrl::fromPublicDisk($navigation->medical_areas_mega_menu_promo_image_path, $request);
        }

        return ['items' => $items, 'promo' => $promo];
    }

    /** @param array<string, mixed> $footer @return array<string, mixed> */
    private function publicFooter(array $footer, SupportedLocale $locale): array
    {
        $columns = [];
        foreach ($footer['columns'] as $key => $column) {
            $items = [];
            foreach ($column['items'] as $item) {
                $href = ($item['link_type'] ?? 'internal') === 'external'
                    ? ($item['external_url'] ?? null)
                    : $this->target((string) ($item['target'] ?? ''), $locale)['href'];
                if (($item['is_active'] ?? true) && filled($href)) {
                    $items[] = ['target' => $item['target'] ?? null, 'label' => $item['label'], 'href' => $href];
                }
            }
            $columns[] = ['key' => $key, 'title' => $column['title'], 'items' => $items];
        }
        $center = $this->center();
        $legalLinks = collect(['privacy' => 'Privacy Policy', 'cookie_policy' => 'Cookie Policy', 'terms_of_service' => 'Termini di servizio'])->map(function (string $label, string $target) use ($locale, $footer): ?array {
            if (($footer['legal_visibility'][$target] ?? true) === false) {
                return null;
            }
            $resolved = $this->target($target, $locale);

            return $resolved['href'] ? ['target' => $target, 'label' => $label, 'href' => $resolved['href']] : null;
        })->filter()->values()->all();
        if (($footer['legal_visibility']['cookie_preferences'] ?? true) && $this->target('cookie_preferences')['is_public']) {
            $legalLinks[] = ['target' => 'cookie_preferences', 'label' => SiteNavigationRegistry::TARGETS['cookie_preferences'], 'action' => 'cookie_preferences'];
        }

        return ['brand' => ['description' => $footer['brand_description'], 'booking' => ['action' => 'booking', 'label' => $footer['booking_label']]], 'columns' => $columns, 'center' => $center, 'legal' => ['year' => now()->year, 'legal_company_name' => $center['legal_company_name'], 'vat_number' => $center['vat_number'], 'links' => $legalLinks], 'social' => $center['social'], 'contact_visibility' => $footer['contact_visibility'], 'legal_visibility' => $footer['legal_visibility'], 'social_visibility' => $footer['social_visibility']];
    }

    /** @return array<string, mixed> */
    private function center(): array
    {
        $settings = SiteSetting::current();
        $location = $this->centerData->resolve($settings);
        $social = collect(['instagram' => ['label' => 'Instagram', 'url' => $settings->instagram_url], 'facebook' => ['label' => 'Facebook', 'url' => $settings->facebook_url], 'tiktok' => ['label' => 'TikTok', 'url' => $settings->tiktok_url], 'youtube' => ['label' => 'YouTube', 'url' => $settings->youtube_url], 'linkedin' => ['label' => 'LinkedIn', 'url' => $settings->linkedin_url]])->filter(fn (array $social): bool => filled($social['url']))->map(fn (array $social, string $platform): array => ['platform' => $platform, ...$social])->values()->all();

        return [...$location, 'phone_href' => filled($location['phone']) ? 'tel:'.preg_replace('/[^+0-9]/', '', (string) $location['phone']) : null, 'email_href' => filled($location['email']) ? 'mailto:'.$location['email'] : null, 'legal_company_name' => $settings->legal_company_name, 'vat_number' => $settings->vat_number, 'social' => $social];
    }

    /** @param mixed $menu @return array<string, mixed> */
    private function areasMenuConfiguration(mixed $menu): array
    {
        $menu = is_array($menu) ? $menu : [];
        $ids = array_values(array_unique(array_filter($menu['specialization_ids'] ?? [], static fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))));
        $promo = is_array($menu['promo'] ?? null) ? $menu['promo'] : [];

        return ['title' => filled($menu['title'] ?? null) ? (string) $menu['title'] : 'Aree mediche', 'specialization_ids' => array_map('intval', array_slice($ids, 0, 12)), 'promo' => ['eyebrow' => (string) ($promo['eyebrow'] ?? 'ESPLORA'), 'title' => (string) ($promo['title'] ?? 'Tutte le aree mediche'), 'body' => filled($promo['body'] ?? null) ? (string) $promo['body'] : null, 'cta_label' => (string) ($promo['cta_label'] ?? 'Scopri tutte le aree mediche'), 'cta_target' => filled($promo['cta_target'] ?? null) ? (string) $promo['cta_target'] : 'medical_areas_index', 'cta_link_type' => ($promo['cta_link_type'] ?? 'internal') === 'external' ? 'external' : 'internal', 'cta_external_url' => filled($promo['cta_external_url'] ?? null) ? (string) $promo['cta_external_url'] : null]];
    }

    /** @param mixed $footer @return array<string, mixed> */
    private function footerConfiguration(mixed $footer): array
    {
        $footer = is_array($footer) ? $footer : [];
        $columns = [];
        foreach (SiteNavigationRegistry::FOOTER_COLUMNS as $key => $definition) {
            $saved = is_array($footer['columns'][$key]['items'] ?? null) ? $footer['columns'][$key]['items'] : [];
            if ($saved === []) {
                $saved = array_map(static fn (string $target): array => ['target' => $target, 'label' => null, 'is_active' => true], $definition['targets']);
            }
            $title = $footer['columns'][$key]['title'] ?? null;
            $columns[$key] = ['key' => $key, 'title' => filled($title) && ! ($key === 'information' && strtoupper((string) $title) === 'INFORMAZIONI') ? (string) $title : $definition['title'], 'items' => collect($saved)->filter(fn (mixed $item): bool => is_array($item))->take(5)->map(static function (array $item): array {
                $internal = ($item['link_type'] ?? 'internal') !== 'external';
                $target = $internal && filled($item['target'] ?? null) ? (string) $item['target'] : null;

                return ['label' => filled($item['label'] ?? null) ? (string) $item['label'] : ($target ? SiteNavigationRegistry::TARGETS[$target] : ''), 'link_type' => $internal ? 'internal' : 'external', 'target' => $target, 'external_url' => $internal ? null : (filled($item['external_url'] ?? null) ? (string) $item['external_url'] : null)];
            })->filter(fn (array $item): bool => filled($item['label']))->values()->all()];
        }

        $contactVisibility = is_array($footer['contact_visibility'] ?? null) ? $footer['contact_visibility'] : [];
        $legalVisibility = is_array($footer['legal_visibility'] ?? null) ? $footer['legal_visibility'] : [];
        $socialVisibility = is_array($footer['social_visibility'] ?? null) ? $footer['social_visibility'] : [];

        return ['brand_description' => (string) ($footer['brand_description'] ?? SiteNavigationRegistry::defaults()['footer']['brand_description']), 'booking_label' => (string) ($footer['booking_label'] ?? 'Prenota ora'), 'contact_visibility' => $this->visibilityMap($contactVisibility, ['address', 'phone', 'email', 'hours']), 'legal_visibility' => $this->visibilityMap($legalVisibility, ['privacy', 'cookie_policy', 'terms_of_service', 'cookie_preferences']), 'social_visibility' => collect($socialVisibility)->filter(static fn (mixed $visible, mixed $platform): bool => is_string($platform) && is_bool($visible))->all(), 'columns' => $columns];
    }

    /** @param array<string, mixed> $visibility @param list<string> $keys @return array<string, bool> */
    private function visibilityMap(array $visibility, array $keys): array
    {
        return collect($keys)->mapWithKeys(static fn (string $key): array => [$key => ($visibility[$key] ?? true) !== false])->all();
    }
}
