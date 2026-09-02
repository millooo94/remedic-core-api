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
        $data = $request->validate(['header' => ['required', 'array', 'size:6'], 'header.*.key' => ['required', 'string'], 'header.*.is_active' => ['required', 'boolean'], 'header.*.label' => ['nullable', 'string', 'max:80'], 'header.*.link_type' => ['nullable', 'in:internal,external'], 'header.*.target' => ['nullable', 'string'], 'header.*.external_url' => ['nullable', 'url', 'max:2048']]);
        $keys = array_column($data['header'], 'key');
        abort_unless(count(array_unique($keys)) === 6 && count(array_diff($keys, SiteNavigationRegistry::HEADER_KEYS)) === 0 && count(array_diff(SiteNavigationRegistry::HEADER_KEYS, $keys)) === 0, 422, 'I nodi Header sono fissi e non duplicabili.');
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $configuration['header'] = $data['header'];
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function updateCenterMegaMenu(Request $request)
    {
        $this->rejectUnknown($request, ['center_mega_menu']);
        $data = $request->validate([
            'center_mega_menu' => ['required', 'array'],
            'center_mega_menu.groups' => ['required', 'array', 'size:4'],
            'center_mega_menu.promo' => ['required', 'array'],
            'center_mega_menu.promo.eyebrow' => ['required', 'string', 'max:80'],
            'center_mega_menu.promo.title' => ['required', 'string', 'max:160'],
            'center_mega_menu.promo.body' => ['nullable', 'string', 'max:2000'],
            'center_mega_menu.promo.cta_label' => ['required', 'string', 'max:80'],
            'center_mega_menu.promo.cta_target' => ['nullable', 'string'],
            'center_mega_menu.promo.cta_link_type' => ['nullable', 'in:internal,external'],
            'center_mega_menu.promo.cta_external_url' => ['nullable', 'url', 'max:2048'],
            'center_mega_menu.sections' => ['nullable', 'array', 'size:4'],
            'center_mega_menu.sections.*.key' => ['required_with:center_mega_menu.sections', 'string'],
            'center_mega_menu.sections.*.label' => ['required_with:center_mega_menu.sections', 'string', 'max:160'],
            'center_mega_menu.sections.*.subtitle' => ['nullable', 'string', 'max:160'],
            'center_mega_menu.sections.*.link_type' => ['required_with:center_mega_menu.sections', 'in:internal,external'],
            'center_mega_menu.sections.*.target' => ['nullable', 'string'],
            'center_mega_menu.sections.*.external_url' => ['nullable', 'url', 'max:2048'],
        ]);
        abort_unless($this->hasExactKeys($data['center_mega_menu'], array_filter(['groups', 'promo', isset($data['center_mega_menu']['sections']) ? 'sections' : null])) && isset($data['center_mega_menu']['promo']['eyebrow'], $data['center_mega_menu']['promo']['title'], $data['center_mega_menu']['promo']['cta_label']), 422, 'Il pannello promozionale ha campi fissi.');
        if (($data['center_mega_menu']['promo']['cta_link_type'] ?? 'internal') === 'internal') {
            abort_unless(SiteNavigationRegistry::targetExists((string) ($data['center_mega_menu']['promo']['cta_target'] ?? '')) && ! in_array($data['center_mega_menu']['promo']['cta_target'], ['booking', 'reserved_area'], true), 422, 'Il target della CTA deve essere navigabile.');
        }
        foreach (SiteNavigationRegistry::CENTER_GROUPS as $groupKey => $definition) {
            $group = $data['center_mega_menu']['groups'][$groupKey] ?? null;
            abort_unless(is_array($group) && $this->hasExactKeys($data['center_mega_menu']['groups'], array_keys(SiteNavigationRegistry::CENTER_GROUPS)) && $this->hasExactKeys($group, ['key', 'items']) && $group['key'] === $groupKey, 422, 'I gruppi del mega menu sono fissi.');
            $items = $group['items'] ?? null;
            abort_unless(is_array($items) && count($items) === count($definition['targets']), 422, 'Gli elementi del gruppo sono fissi.');
            $targets = array_column($items, 'target');
            abort_unless(count(array_unique($targets)) === count($targets) && count(array_diff($targets, $definition['targets'])) === 0 && count(array_diff($definition['targets'], $targets)) === 0, 422, 'Un target non appartiene a questo gruppo.');
            foreach ($items as $item) {
                abort_unless($this->hasExactKeys($item, ['target', 'is_active', 'label', 'description']) && is_bool($item['is_active'] ?? null) && (! isset($item['label']) || is_null($item['label']) || is_string($item['label'])) && (! isset($item['description']) || is_null($item['description']) || is_string($item['description'])), 422, 'La configurazione dell’elemento non è valida.');
            }
        }
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $storedSections = collect($configuration['center_mega_menu']['sections'] ?? [])->keyBy('key');
        if (isset($data['center_mega_menu']['sections'])) {
            $data['center_mega_menu']['sections'] = collect($data['center_mega_menu']['sections'])
                ->map(fn (array $section): array => [...$section, 'icon_path' => $storedSections->get($section['key'])['icon_path'] ?? null])
                ->all();
        }
        $configuration['center_mega_menu'] = $data['center_mega_menu'];
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function updateMedicalAreasMegaMenu(Request $request)
    {
        $this->rejectUnknown($request, ['medical_areas_mega_menu']);
        $data = $request->validate(['medical_areas_mega_menu' => ['required', 'array'], 'medical_areas_mega_menu.title' => ['nullable', 'string', 'max:160'], 'medical_areas_mega_menu.specialization_ids' => ['required', 'array', 'max:12'], 'medical_areas_mega_menu.specialization_ids.*' => ['integer', 'distinct', 'exists:specialization_web_profiles,specialization_id'], 'medical_areas_mega_menu.promo' => ['required', 'array'], 'medical_areas_mega_menu.promo.eyebrow' => ['required', 'string', 'max:80'], 'medical_areas_mega_menu.promo.title' => ['required', 'string', 'max:160'], 'medical_areas_mega_menu.promo.body' => ['nullable', 'string', 'max:2000'], 'medical_areas_mega_menu.promo.cta_label' => ['required', 'string', 'max:80'], 'medical_areas_mega_menu.promo.cta_target' => ['nullable', 'string'], 'medical_areas_mega_menu.promo.cta_link_type' => ['nullable', 'in:internal,external'], 'medical_areas_mega_menu.promo.cta_external_url' => ['nullable', 'url', 'max:2048']]);
        abort_unless($this->hasExactKeys($data['medical_areas_mega_menu'], array_filter(['specialization_ids', 'promo', isset($data['medical_areas_mega_menu']['title']) ? 'title' : null])), 422, 'La configurazione del mega menu Aree mediche non è valida.');
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
            'footer.columns.*.items.*.link_type' => ['required', 'in:internal,external'],
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
}
