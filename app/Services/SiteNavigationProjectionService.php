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

        return [
            'configuration' => $configuration,
            'targets' => $this->targets(),
            'media' => ['center_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->center_mega_menu_promo_image_path, $request), 'medical_areas_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->medical_areas_mega_menu_promo_image_path, $request)],
            'area_candidates' => SpecializationWebProfile::query()->with('specialization')->orderBy('list_sort_order')->get()->map(fn (SpecializationWebProfile $profile): array => ['specialization_id' => $profile->specialization_id, 'name' => $profile->specialization->name, 'icon_url' => PublicMediaUrl::fromPublicDisk($profile->specialization->icon_path, $request), 'public_slug' => $profile->slug, 'short_description' => $profile->short_description, 'publication_state' => $profile->isEffectivelyVisible() ? 'published' : 'not_public'])->all(),
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

            $target = $this->target((string) $definition['target'], $locale);
            if ($target['href'] === null) {
                continue;
            }
            $items[] = ['key' => $item['key'], 'type' => $definition['type'], 'label' => $item['label'] ?: $definition['label'], 'href' => $target['href']];
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
        $promo = $menu['promo'];
        $cta = $this->target($promo['cta_target'], $locale);
        if ($cta['href'] === null) {
            $promo['cta_label'] = null;
            $promo['cta_target'] = null;
        } else {
            $promo['href'] = $cta['href'];
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

            return ['key' => $key, 'is_active' => (bool) ($item['is_active'] ?? true), 'label' => filled($item['label'] ?? null) ? (string) $item['label'] : null];
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

        return ['groups' => $groups, 'promo' => ['eyebrow' => (string) ($promo['eyebrow'] ?? 'ESPLORA'), 'title' => (string) ($promo['title'] ?? 'Conosci Remedic'), 'body' => filled($promo['body'] ?? null) ? (string) $promo['body'] : null, 'cta_label' => (string) ($promo['cta_label'] ?? 'Scopri il centro'), 'cta_target' => (string) ($promo['cta_target'] ?? 'center')]];
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
        $target = $this->target('medical_areas_index', $locale);
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
                $target = $this->target($item['target'], $locale);
                if ($item['is_active'] && $target['href'] !== null) {
                    $items[] = ['target' => $item['target'], 'label' => $item['label'] ?: SiteNavigationRegistry::TARGETS[$item['target']], 'href' => $target['href']];
                }
            }
            $columns[] = ['key' => $key, 'title' => $column['title'], 'items' => $items];
        }
        $center = $this->center();
        $legalLinks = collect(['privacy' => 'Privacy Policy', 'cookie_policy' => 'Cookie Policy', 'terms_of_service' => 'Termini di servizio'])->map(function (string $label, string $target) use ($locale): ?array {
            $resolved = $this->target($target, $locale);

            return $resolved['href'] ? ['target' => $target, 'label' => $label, 'href' => $resolved['href']] : null;
        })->filter()->values()->all();
        if ($this->target('cookie_preferences')['is_public']) {
            $legalLinks[] = ['target' => 'cookie_preferences', 'label' => SiteNavigationRegistry::TARGETS['cookie_preferences'], 'action' => 'cookie_preferences'];
        }

        return ['brand' => ['description' => $footer['brand_description'], 'booking' => ['action' => 'booking', 'label' => $footer['booking_label']]], 'columns' => $columns, 'center' => $center, 'legal' => ['year' => now()->year, 'legal_company_name' => $center['legal_company_name'], 'vat_number' => $center['vat_number'], 'links' => $legalLinks], 'social' => $center['social']];
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

        return ['specialization_ids' => array_map('intval', array_slice($ids, 0, 12)), 'promo' => ['eyebrow' => (string) ($promo['eyebrow'] ?? 'ESPLORA'), 'title' => (string) ($promo['title'] ?? 'Tutte le aree mediche'), 'body' => filled($promo['body'] ?? null) ? (string) $promo['body'] : null, 'cta_label' => (string) ($promo['cta_label'] ?? 'Scopri tutte le aree mediche')]];
    }

    /** @param mixed $footer @return array<string, mixed> */
    private function footerConfiguration(mixed $footer): array
    {
        $footer = is_array($footer) ? $footer : [];
        $columns = [];
        foreach (SiteNavigationRegistry::FOOTER_COLUMNS as $key => $definition) {
            $saved = is_array($footer['columns'][$key]['items'] ?? null) ? $footer['columns'][$key]['items'] : [];
            $byTarget = collect($saved)->keyBy('target');
            $targets = collect($saved)->pluck('target')->filter(fn ($target): bool => in_array($target, $definition['targets'], true))->unique()->values()->all();
            $targets = [...$targets, ...array_values(array_filter($definition['targets'], fn (string $target): bool => ! in_array($target, $targets, true)))];
            $columns[$key] = ['key' => $key, 'title' => filled($footer['columns'][$key]['title'] ?? null) ? (string) $footer['columns'][$key]['title'] : $definition['title'], 'items' => collect($targets)->map(static function (string $target) use ($byTarget): array {
                $item = $byTarget->get($target, []);

                return ['target' => $target, 'is_active' => (bool) ($item['is_active'] ?? true), 'label' => filled($item['label'] ?? null) ? (string) $item['label'] : null];
            })->all()];
        }

        return ['brand_description' => (string) ($footer['brand_description'] ?? SiteNavigationRegistry::defaults()['footer']['brand_description']), 'booking_label' => (string) ($footer['booking_label'] ?? 'Prenota ora'), 'columns' => $columns];
    }
}
