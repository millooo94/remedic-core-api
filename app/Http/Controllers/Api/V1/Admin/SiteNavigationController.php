<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SiteNavigationInitializer;
use App\Services\SiteNavigationProjectionService;
use App\Support\Navigation\SiteNavigationRegistry;
use Illuminate\Http\Request;

class SiteNavigationController extends Controller
{
    public function __construct(private readonly SiteNavigationProjectionService $projection, private readonly SiteNavigationInitializer $initializer) {}

    public function show(Request $request)
    {
        return response()->json(['data' => $this->projection->admin($this->initializer->initialize(), $request)]);
    }

    public function updateHeader(Request $request)
    {
        $this->rejectUnknown($request, ['header']);
        $data = $request->validate(['header' => ['required', 'array', 'size:6'], 'header.*.key' => ['required', 'string'], 'header.*.is_active' => ['required', 'boolean'], 'header.*.label' => ['nullable', 'string', 'max:80'], 'header.*.link_type' => ['nullable', 'in:internal,external,none'], 'header.*.target' => ['nullable', 'string'], 'header.*.external_url' => ['nullable', 'url', 'max:2048']]);
        $keys = array_column($data['header'], 'key');
        abort_unless(count(array_unique($keys)) === 6 && count(array_diff($keys, SiteNavigationRegistry::HEADER_KEYS)) === 0 && count(array_diff(SiteNavigationRegistry::HEADER_KEYS, $keys)) === 0, 422, 'I nodi Header sono fissi e non duplicabili.');
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $configuration['header'] = array_map(fn (array $item): array => $this->normalizeDestination($item), $data['header']);
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function updateCenterMegaMenu(Request $request)
    {
        $this->rejectUnknown($request, ['center_mega_menu']);
        $data = $request->validate([
            'center_mega_menu' => ['required', 'array'],
            'center_mega_menu.groups' => ['required', 'array', 'size:4'],
            'center_mega_menu.groups.*.key' => ['required', 'string'],
            'center_mega_menu.groups.*.label' => ['required', 'string', 'max:160'],
            'center_mega_menu.groups.*.is_active' => ['required', 'boolean'],
            'center_mega_menu.groups.*.items' => ['required', 'array'],
            'center_mega_menu.groups.*.items.*.key' => ['required', 'string'],
            'center_mega_menu.groups.*.items.*.target' => ['nullable', 'string'],
            'center_mega_menu.groups.*.items.*.is_active' => ['required', 'boolean'],
            'center_mega_menu.groups.*.items.*.label' => ['nullable', 'string', 'max:160'],
            'center_mega_menu.groups.*.items.*.description' => ['nullable', 'string', 'max:160'],
            'center_mega_menu.groups.*.items.*.link_type' => ['required', 'in:internal,external,none'],
            'center_mega_menu.groups.*.items.*.external_url' => ['nullable', 'url', 'max:2048'],
            'center_mega_menu.promo' => ['required', 'array'],
            'center_mega_menu.promo.eyebrow' => ['required', 'string', 'max:80'],
            'center_mega_menu.promo.title' => ['required', 'string', 'max:160'],
            'center_mega_menu.promo.body' => ['nullable', 'string', 'max:2000'],
            'center_mega_menu.promo.cta_label' => ['required', 'string', 'max:80'],
            'center_mega_menu.promo.cta_target' => ['nullable', 'string'],
            'center_mega_menu.promo.cta_link_type' => ['nullable', 'in:internal,external,none'],
            'center_mega_menu.promo.cta_external_url' => ['nullable', 'url', 'max:2048'],
        ]);
        abort_unless($this->hasExactKeys($data['center_mega_menu'], ['groups', 'promo']) && isset($data['center_mega_menu']['promo']['eyebrow'], $data['center_mega_menu']['promo']['title'], $data['center_mega_menu']['promo']['cta_label']), 422, 'Il pannello promozionale ha campi fissi.');
        if (($data['center_mega_menu']['promo']['cta_link_type'] ?? 'internal') === 'internal') {
            abort_unless(SiteNavigationRegistry::targetExists((string) ($data['center_mega_menu']['promo']['cta_target'] ?? '')) && ! in_array($data['center_mega_menu']['promo']['cta_target'], ['booking', 'reserved_area'], true), 422, 'Il target della CTA deve essere navigabile.');
        }
        $data['center_mega_menu']['promo'] = $this->normalizeDestination($data['center_mega_menu']['promo'], 'cta_link_type', 'cta_target', 'cta_external_url');
        $data['center_mega_menu']['groups'] = array_map(fn (array $group): array => [...$group, 'items' => array_map(fn (array $item): array => $this->normalizeDestination($item), $group['items'])], $data['center_mega_menu']['groups']);
        $groupsByKey = collect($data['center_mega_menu']['groups'])->keyBy('key');
        abort_unless($groupsByKey->count() === count(SiteNavigationRegistry::CENTER_GROUPS) && $groupsByKey->keys()->sort()->values()->all() === collect(array_keys(SiteNavigationRegistry::CENTER_GROUPS))->sort()->values()->all(), 422, 'I gruppi del mega menu sono fissi.');
        foreach (SiteNavigationRegistry::CENTER_GROUPS as $groupKey => $definition) {
            $group = $groupsByKey->get($groupKey);
            abort_unless(is_array($group) && $this->hasExactKeys($group, ['key', 'label', 'is_active', 'items']) && $group['key'] === $groupKey && is_bool($group['is_active']), 422, 'I gruppi del mega menu sono fissi.');
            $items = $group['items'] ?? null;
            abort_unless(is_array($items) && count($items) === count($definition['items']), 422, 'Gli elementi del gruppo sono fissi.');
            $itemKeys = array_column($items, 'key');
            abort_unless(count(array_unique($itemKeys)) === count($itemKeys) && count(array_diff($itemKeys, $definition['items'])) === 0 && count(array_diff($definition['items'], $itemKeys)) === 0, 422, 'Una voce non appartiene a questo gruppo.');
            foreach ($items as $item) {
                abort_unless($this->hasExactKeys($item, ['key', 'target', 'is_active', 'label', 'description', 'link_type', 'external_url']) && is_bool($item['is_active'] ?? null), 422, 'La configurazione della voce non è valida.');
                if ($item['link_type'] === 'internal') {
                    abort_unless(SiteNavigationRegistry::targetExists((string) ($item['target'] ?? '')), 422, 'Il target della voce deve essere navigabile.');
                }
                $item = array_intersect_key($item, array_flip(['target', 'is_active', 'label', 'description']));
                abort_unless($this->hasExactKeys($item, ['target', 'is_active', 'label', 'description']) && is_bool($item['is_active'] ?? null) && (! isset($item['label']) || is_null($item['label']) || is_string($item['label'])) && (! isset($item['description']) || is_null($item['description']) || is_string($item['description'])), 422, 'La configurazione dell’elemento non è valida.');
            }
        }
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $storedItems = collect($configuration['center_mega_menu']['groups'])->flatMap(fn (array $group): array => collect($group['items'])->mapWithKeys(fn (array $item): array => [$group['key'].':'.$item['key'] => $item])->all());
        $data['center_mega_menu']['groups'] = collect($data['center_mega_menu']['groups'])->map(fn (array $group): array => [...$group, 'items' => collect($group['items'])->map(fn (array $item): array => [...$item, 'icon_path' => $storedItems->get($group['key'].':'.$item['key'])['icon_path'] ?? null])->all()])->all();
        $configuration['center_mega_menu'] = $data['center_mega_menu'];
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function updateMedicalAreasMegaMenu(Request $request)
    {
        $this->rejectUnknown($request, ['medical_areas_mega_menu']);
        $data = $request->validate(['medical_areas_mega_menu' => ['required', 'array'], 'medical_areas_mega_menu.title' => ['nullable', 'string', 'max:160'], 'medical_areas_mega_menu.specialization_ids' => ['required', 'array', 'max:12'], 'medical_areas_mega_menu.specialization_ids.*' => ['integer', 'distinct', 'exists:specialization_web_profiles,specialization_id'], 'medical_areas_mega_menu.promo' => ['required', 'array'], 'medical_areas_mega_menu.promo.eyebrow' => ['required', 'string', 'max:80'], 'medical_areas_mega_menu.promo.title' => ['required', 'string', 'max:160'], 'medical_areas_mega_menu.promo.body' => ['nullable', 'string', 'max:2000'], 'medical_areas_mega_menu.promo.cta_label' => ['required', 'string', 'max:80'], 'medical_areas_mega_menu.promo.cta_target' => ['nullable', 'string'], 'medical_areas_mega_menu.promo.cta_link_type' => ['nullable', 'in:internal,external,none'], 'medical_areas_mega_menu.promo.cta_external_url' => ['nullable', 'url', 'max:2048']]);
        abort_unless($this->hasExactKeys($data['medical_areas_mega_menu'], array_filter(['specialization_ids', 'promo', isset($data['medical_areas_mega_menu']['title']) ? 'title' : null])), 422, 'La configurazione del mega menu Aree mediche non è valida.');
        $data['medical_areas_mega_menu']['promo'] = $this->normalizeDestination($data['medical_areas_mega_menu']['promo'], 'cta_link_type', 'cta_target', 'cta_external_url');
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $configuration['medical_areas_mega_menu'] = $data['medical_areas_mega_menu'];
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function updateFooter(Request $request)
    {
        $this->rejectUnknown($request, ['footer']);
        $data = $request->validate([
            'footer' => ['required', 'array'],
            'footer.brand_description' => ['required', 'string', 'max:2000'],
            'footer.booking_label' => ['required', 'string', 'max:80'],
            'footer.contact_visibility' => ['nullable', 'array'],
            'footer.contact_visibility.*' => ['boolean'],
            'footer.legal_visibility' => ['nullable', 'array'],
            'footer.legal_visibility.*' => ['boolean'],
            'footer.social_visibility' => ['nullable', 'array'],
            'footer.social_visibility.*' => ['boolean'],
            'footer.columns' => ['required', 'array', 'size:3'],
            'footer.columns.*.key' => ['required', 'string'],
            'footer.columns.*.title' => ['required', 'string', 'max:80'],
            'footer.columns.*.items' => ['required', 'array', 'max:5'],
            'footer.columns.*.items.*.label' => ['required', 'string', 'max:120'],
            'footer.columns.*.items.*.link_type' => ['required', 'in:internal,external,none'],
            'footer.columns.*.items.*.target' => ['nullable', 'string'],
            'footer.columns.*.items.*.external_url' => ['nullable', 'url', 'max:2048'],
        ]);
        abort_unless($this->hasOnlyKeys($data['footer'], ['brand_description', 'booking_label', 'contact_visibility', 'legal_visibility', 'social_visibility', 'columns']) && $this->hasExactKeys($data['footer']['columns'], array_keys(SiteNavigationRegistry::FOOTER_COLUMNS)), 422, 'Le colonne Footer sono fisse.');
        $data['footer']['contact_visibility'] = $this->visibilityMap($data['footer']['contact_visibility'] ?? [], ['address', 'phone', 'email', 'hours']);
        $data['footer']['legal_visibility'] = $this->visibilityMap($data['footer']['legal_visibility'] ?? [], ['privacy', 'cookie_policy', 'terms_of_service', 'cookie_preferences']);
        $data['footer']['social_visibility'] = $data['footer']['social_visibility'] ?? [];
        foreach (SiteNavigationRegistry::FOOTER_COLUMNS as $key => $definition) {
            $column = $data['footer']['columns'][$key] ?? null;
            abort_unless(is_array($column) && $this->hasExactKeys($column, ['key', 'title', 'items']) && $column['key'] === $key && is_string($column['title'] ?? null) && is_array($column['items'] ?? null), 422, 'La colonna Footer non è valida.');
            foreach ($column['items'] as $item) {
                abort_unless($this->hasExactKeys($item, ['label', 'link_type', 'target', 'external_url']), 422, 'Un elemento Footer non è valido.');
                if ($item['link_type'] === 'internal') {
                    abort_unless(SiteNavigationRegistry::targetExists((string) ($item['target'] ?? '')) && ! in_array($item['target'], ['booking', 'reserved_area', 'cookie_preferences'], true), 422, 'Il target Footer deve essere una pagina navigabile.');
                }
            }
            $data['footer']['columns'][$key]['items'] = array_map(fn (array $item): array => $this->normalizeDestination($item), $column['items']);
        }
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $configuration['footer'] = $data['footer'];
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    private function rejectUnknown(Request $request, array $allowed): void
    {
        abort_unless(count(array_diff(array_keys($request->all()), $allowed)) === 0, 422, 'Il payload non contiene campi consentiti.');
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    private function hasOnlyKeys(array $value, array $allowed): bool
    {
        return count(array_diff(array_keys($value), $allowed)) === 0;
    }

    /** @param array<string, mixed> $visibility @param list<string> $keys @return array<string, bool> */
    private function visibilityMap(array $visibility, array $keys): array
    {
        return collect($keys)->mapWithKeys(static fn (string $key): array => [$key => ($visibility[$key] ?? true) !== false])->all();
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function normalizeDestination(array $value, string $typeKey = 'link_type', string $targetKey = 'target', string $externalUrlKey = 'external_url'): array
    {
        $type = in_array($value[$typeKey] ?? 'internal', ['internal', 'external', 'none'], true) ? $value[$typeKey] : 'internal';
        $value[$typeKey] = $type;

        if ($type === 'none') {
            $value[$targetKey] = null;
            $value[$externalUrlKey] = null;
        }

        return $value;
    }
}
