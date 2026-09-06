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
        $configuration['center_mega_menu']['groups'] = collect($configuration['center_mega_menu']['groups'])
            ->map(function (array $group) use ($request): array {
                $group['items'] = collect($group['items'])
                    ->map(fn (array $item): array => [...$item, 'icon_url' => PublicMediaUrl::fromPublicDisk($item['icon_path'] ?? null, $request)])
                    ->map(function (array $item): array {
                        unset($item['icon_path']);

                        return $item;
                    })->all();

                return $group;
            })->all();

        return [
            'id' => $navigation->getKey(),
            'configuration' => $configuration,
            'targets' => $this->targets(),
            'media' => ['center_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->center_mega_menu_promo_image_path, $request), 'medical_areas_mega_menu_promo_image_url' => PublicMediaUrl::fromPublicDisk($navigation->medical_areas_mega_menu_promo_image_path, $request)],
            'area_candidates' => SpecializationWebProfile::query()->with('specialization')->orderBy('specialization_id')->get()->map(fn (SpecializationWebProfile $profile): array => ['specialization_id' => $profile->specialization_id, 'name' => $profile->specialization->name, 'icon_url' => PublicMediaUrl::fromPublicDisk($profile->specialization->icon_path, $request), 'public_slug' => $profile->slug, 'short_description' => $profile->specialization->short_description, 'is_visible' => $profile->isEffectivelyVisible()])->all(),
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

            $linkType = $this->linkType($item['link_type'] ?? null);
            $href = $this->destinationHref($linkType, $item['target'] ?? $definition['target'], $item['external_url'] ?? null, $locale);
            if ($href === null && $linkType !== 'none') {
                continue;
            }
            $items[] = ['key' => $item['key'], 'type' => $definition['type'], 'label' => $item['label'] ?: $definition['label'], 'link_type' => $linkType, 'href' => $href];
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
    public function targets(): array
    {
        return collect(SiteNavigationRegistry::TARGETS)
            ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, ...$this->target($key)])
            ->filter(fn (array $target): bool => $target['is_action'] || $target['is_public'])
            ->values()->all();
    }

    /** @return array{href: ?string, is_action: bool, is_public: bool, action?: string} */
    public function target(string $key, ?SupportedLocale $locale = null, array $context = []): array
    {
        $locale ??= SupportedLocale::IT;
        if ($external = SiteNavigationRegistry::fixedExternalTarget($key)) {
            return ['href' => $external['href'], 'is_action' => false, 'is_public' => true];
        }
        if ($key === 'cookie_preferences') {
            return ['href' => null, 'is_action' => true, 'is_public' => (bool) $this->consentConfiguration->initialize()->is_enabled, 'action' => 'cookie_preferences'];
        }
        if (in_array($key, ['booking', 'reserved_area', 'newsletter_subscription'], true)) {
            return ['href' => null, 'is_action' => true, 'is_public' => true, 'action' => $key];
        }
        if ($key === 'external_url') {
            $href = isset($context['external_url']) && is_string($context['external_url']) ? trim($context['external_url']) : null;

            return ['href' => filled($href) ? $href : null, 'is_action' => true, 'is_public' => filled($href), 'action' => $key];
        }
        if (in_array($key, ['phone', 'whatsapp', 'map', 'email'], true)) {
            $center = $this->centerData->resolve(SiteSetting::current());
            $href = match ($key) {
                'phone' => filled($center['phone']) ? 'tel:'.preg_replace('/[^0-9+]/', '', (string) $center['phone']) : null,
                'whatsapp' => filled($center['whatsapp_number']) ? $this->whatsappHref((string) $center['whatsapp_number'], $context['whatsapp_message'] ?? null) : null,
                'map' => $center['directions_href'],
                'email' => filled($center['email']) ? 'mailto:'.$center['email'] : null,
            };

            return ['href' => $href, 'is_action' => true, 'is_public' => filled($href), 'action' => $key];
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
            }, $locale) : null, 'is_action' => false, 'is_public' => $public];
        }

        $page = Page::query()->with('translations')->where('internal_key', $key === 'cookie_policy' ? 'cookie-policy' : $key)->first();
        $translation = $page?->translations->firstWhere('locale', $locale);
        $public = ($page?->isPubliclyAvailable() ?? false)
            && ($locale === SupportedLocale::IT || $translation?->isPubliclyAvailable());
        $slug = $locale === SupportedLocale::IT ? $page?->slug : $translation?->slug;

        return ['href' => $public && $slug ? $this->routes->page($locale, $slug) : null, 'is_action' => false, 'is_public' => $public];
    }

    private function whatsappHref(string $number, mixed $message): string
    {
        $href = 'https://wa.me/'.preg_replace('/\\D+/', '', $number);

        return is_string($message) && trim($message) !== '' ? $href.'?text='.rawurlencode(trim($message)) : $href;
    }

    /** @param array<string, mixed> $menu @return array<string, mixed> */
    private function publicCenterMenu(array $menu, SiteNavigation $navigation, Request $request, SupportedLocale $locale): array
    {
        $groups = [];
        foreach ($menu['groups'] as $group) {
            if (! ($group['is_active'] ?? true)) {
                continue;
            }
            $items = [];
            foreach ($group['items'] as $item) {
                if (! $item['is_active']) {
                    continue;
                }
                $linkType = $this->linkType($item['link_type'] ?? null);
                if ($linkType === 'none' && ! filled($item['label'] ?? null)) {
                    continue;
                }
                $href = $this->destinationHref($linkType, $item['target'] ?? null, $item['external_url'] ?? null, $locale);
                if (! filled($href) && $linkType !== 'none') {
                    continue;
                }
                $target = (string) ($item['target'] ?? '');
                $items[] = ['target' => $target ?: null, 'label' => $item['label'] ?: (SiteNavigationRegistry::TARGETS[$target] ?? $target), 'description' => $item['description'], 'link_type' => $linkType, 'href' => $href, 'icon_url' => PublicMediaUrl::fromPublicDisk($item['icon_path'] ?? null, $request)];
            }
            if ($items !== []) {
                $groups[] = ['key' => $group['key'], 'label' => $group['label'], 'items' => $items];
            }
        }
        $promo = $menu['promo'];
        $linkType = $this->linkType($promo['cta_link_type'] ?? null);
        $href = $this->destinationHref($linkType, $promo['cta_target'] ?? null, $promo['cta_external_url'] ?? null, $locale);
        $promo['cta_link_type'] = $linkType;
        if ($href === null && $linkType !== 'none') {
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

            $linkType = in_array($item['link_type'] ?? null, ['internal', 'external', 'none'], true) ? $item['link_type'] : 'internal';

            return ['key' => $key, 'is_active' => (bool) ($item['is_active'] ?? true), 'label' => filled($item['label'] ?? null) ? (string) $item['label'] : null, 'link_type' => $linkType, 'target' => $linkType === 'none' ? null : (isset($item['target']) ? (string) $item['target'] : (SiteNavigationRegistry::HEADER[$key]['target'] ?? null)), 'external_url' => $linkType === 'none' ? null : (filled($item['external_url'] ?? null) ? (string) $item['external_url'] : null)];
        })->all();
    }

    /** @param mixed $menu @return array<string, mixed> */
    private function centerMenuConfiguration(mixed $menu): array
    {
        $menu = is_array($menu) ? $menu : [];
        $legacySections = collect($menu['sections'] ?? [])
            ->filter(fn ($section): bool => is_array($section) && is_string($section['key'] ?? null))
            ->keyBy('key');
        $savedGroups = collect(is_array($menu['groups'] ?? null) ? $menu['groups'] : [])
            ->filter(fn ($group): bool => is_array($group) && is_string($group['key'] ?? null))
            ->keyBy('key');
        $orderedGroupKeys = [];
        foreach (is_array($menu['groups'] ?? null) ? $menu['groups'] : [] as $group) {
            $groupKey = $group['key'] ?? null;
            if (is_string($groupKey) && isset(SiteNavigationRegistry::CENTER_GROUPS[$groupKey]) && ! in_array($groupKey, $orderedGroupKeys, true)) {
                $orderedGroupKeys[] = $groupKey;
            }
        }
        $orderedGroupKeys = [...$orderedGroupKeys, ...array_values(array_filter(array_keys(SiteNavigationRegistry::CENTER_GROUPS), static fn (string $key): bool => ! in_array($key, $orderedGroupKeys, true)))];
        $groups = [];
        foreach ($orderedGroupKeys as $key) {
            $definition = SiteNavigationRegistry::CENTER_GROUPS[$key];
            $savedGroup = $savedGroups->get($key, []);
            $legacy = $legacySections->get($key, []);
            $saved = is_array($savedGroup['items'] ?? null) ? $savedGroup['items'] : [];
            $byKey = collect($saved)->filter(fn ($item): bool => is_array($item))->keyBy('key');
            $orderedKeys = [];
            foreach ($saved as $item) {
                $itemKey = $item['key'] ?? null;
                if (is_string($itemKey) && in_array($itemKey, $definition['items'], true) && ! in_array($itemKey, $orderedKeys, true)) {
                    $orderedKeys[] = $itemKey;
                }
            }
            $orderedKeys = [...$orderedKeys, ...array_values(array_filter($definition['items'], static fn (string $itemKey): bool => ! in_array($itemKey, $orderedKeys, true)))];
            $groups[] = ['key' => $key, 'label' => filled($savedGroup['label'] ?? null) ? (string) $savedGroup['label'] : (filled($legacy['label'] ?? null) ? (string) $legacy['label'] : $definition['label']), 'is_active' => (bool) ($savedGroup['is_active'] ?? true), 'items' => collect($orderedKeys)->map(static function (string $itemKey) use ($byKey, $legacy): array {
                $item = $byKey->get($itemKey, []);
                $legacyItem = ($legacy['target'] ?? null) === $itemKey ? $legacy : [];
                $isNewSlot = $item === [] && $legacyItem === [];
                $linkType = in_array($item['link_type'] ?? $legacyItem['link_type'] ?? null, ['internal', 'external', 'none'], true) ? ($item['link_type'] ?? $legacyItem['link_type']) : ($isNewSlot ? 'none' : 'internal');

                return ['key' => $itemKey, 'target' => $linkType === 'none' ? null : (filled($item['target'] ?? null) ? (string) $item['target'] : ($isNewSlot ? null : $itemKey)), 'is_active' => $isNewSlot ? false : (bool) ($item['is_active'] ?? true), 'label' => filled($item['label'] ?? null) ? (string) $item['label'] : (filled($legacyItem['label'] ?? null) ? (string) $legacyItem['label'] : null), 'description' => filled($item['description'] ?? null) ? (string) $item['description'] : (filled($legacyItem['subtitle'] ?? null) ? (string) $legacyItem['subtitle'] : null), 'icon_path' => filled($item['icon_path'] ?? null) ? (string) $item['icon_path'] : (filled($legacyItem['icon_path'] ?? null) ? (string) $legacyItem['icon_path'] : null), 'link_type' => $linkType, 'external_url' => $linkType === 'none' ? null : (filled($item['external_url'] ?? null) ? (string) $item['external_url'] : null)];
            })->all()];
        }
        $promo = is_array($menu['promo'] ?? null) ? $menu['promo'] : [];

        $linkType = $this->linkType($promo['cta_link_type'] ?? null);

        return ['groups' => $groups, 'promo' => ['eyebrow' => (string) ($promo['eyebrow'] ?? 'ESPLORA'), 'title' => (string) ($promo['title'] ?? 'Conosci Remedic'), 'body' => filled($promo['body'] ?? null) ? (string) $promo['body'] : null, 'cta_label' => (string) ($promo['cta_label'] ?? 'Scopri il centro'), 'cta_target' => $linkType === 'none' ? null : (filled($promo['cta_target'] ?? null) ? (string) $promo['cta_target'] : 'center'), 'cta_link_type' => $linkType, 'cta_external_url' => $linkType === 'none' ? null : (filled($promo['cta_external_url'] ?? null) ? (string) $promo['cta_external_url'] : null)]];
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
        $linkType = $this->linkType($menu['promo']['cta_link_type'] ?? null);
        $href = $this->destinationHref($linkType, $menu['promo']['cta_target'] ?? null, $menu['promo']['cta_external_url'] ?? null, $locale);
        $promo = $href === null && $linkType !== 'none' ? null : [...$menu['promo'], 'cta_link_type' => $linkType, 'href' => $href];
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
                $linkType = $this->linkType($item['link_type'] ?? null);
                $href = $this->destinationHref($linkType, $item['target'] ?? null, $item['external_url'] ?? null, $locale);
                if (($item['is_active'] ?? true) && (filled($href) || $linkType === 'none')) {
                    $items[] = ['target' => $linkType === 'internal' ? ($item['target'] ?? null) : null, 'label' => $item['label'], 'link_type' => $linkType, 'href' => $href];
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

        $linkType = $this->linkType($promo['cta_link_type'] ?? null);

        return ['title' => filled($menu['title'] ?? null) ? (string) $menu['title'] : 'Aree mediche', 'specialization_ids' => array_map('intval', array_slice($ids, 0, 12)), 'promo' => ['eyebrow' => (string) ($promo['eyebrow'] ?? 'ESPLORA'), 'title' => (string) ($promo['title'] ?? 'Tutte le aree mediche'), 'body' => filled($promo['body'] ?? null) ? (string) $promo['body'] : null, 'cta_label' => (string) ($promo['cta_label'] ?? 'Scopri tutte le aree mediche'), 'cta_target' => $linkType === 'none' ? null : (filled($promo['cta_target'] ?? null) ? (string) $promo['cta_target'] : 'medical_areas_index'), 'cta_link_type' => $linkType, 'cta_external_url' => $linkType === 'none' ? null : (filled($promo['cta_external_url'] ?? null) ? (string) $promo['cta_external_url'] : null)]];
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
                $linkType = in_array($item['link_type'] ?? null, ['internal', 'external', 'none'], true) ? $item['link_type'] : 'internal';
                $target = $linkType === 'internal' && filled($item['target'] ?? null) ? (string) $item['target'] : null;

                return ['label' => filled($item['label'] ?? null) ? (string) $item['label'] : ($target ? SiteNavigationRegistry::TARGETS[$target] : ''), 'link_type' => $linkType, 'target' => $target, 'external_url' => $linkType === 'external' && filled($item['external_url'] ?? null) ? (string) $item['external_url'] : null];
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

    private function linkType(mixed $value): string
    {
        return in_array($value, ['internal', 'external', 'none'], true) ? $value : 'internal';
    }

    private function destinationHref(string $linkType, mixed $target, mixed $externalUrl, SupportedLocale $locale): ?string
    {
        if ($linkType === 'none') {
            return null;
        }

        if ($linkType === 'external') {
            return filled($externalUrl) ? (string) $externalUrl : null;
        }

        return $this->target((string) $target, $locale)['href'];
    }
}
