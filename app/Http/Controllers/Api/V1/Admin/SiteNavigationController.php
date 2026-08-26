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
        $data = $request->validate(['header' => ['required', 'array', 'size:6'], 'header.*.key' => ['required', 'string'], 'header.*.is_active' => ['required', 'boolean'], 'header.*.label' => ['nullable', 'string', 'max:80']]);
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
            'center_mega_menu.promo.cta_target' => ['required', 'string'],
        ]);
        abort_unless($this->hasExactKeys($data['center_mega_menu'], ['groups', 'promo']) && $this->hasExactKeys($data['center_mega_menu']['promo'], ['eyebrow', 'title', 'body', 'cta_label', 'cta_target']), 422, 'Il pannello promozionale ha campi fissi.');
        abort_unless(SiteNavigationRegistry::targetExists($data['center_mega_menu']['promo']['cta_target']) && ! in_array($data['center_mega_menu']['promo']['cta_target'], ['booking', 'reserved_area'], true), 422, 'Il target della CTA deve essere un target navigabile.');
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
        $configuration['center_mega_menu'] = $data['center_mega_menu'];
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function updateMedicalAreasMegaMenu(Request $request)
    {
        $this->rejectUnknown($request, ['medical_areas_mega_menu']);
        $data = $request->validate(['medical_areas_mega_menu' => ['required', 'array'], 'medical_areas_mega_menu.specialization_ids' => ['required', 'array', 'max:12'], 'medical_areas_mega_menu.specialization_ids.*' => ['integer', 'distinct', 'exists:specialization_web_profiles,specialization_id'], 'medical_areas_mega_menu.promo' => ['required', 'array'], 'medical_areas_mega_menu.promo.eyebrow' => ['required', 'string', 'max:80'], 'medical_areas_mega_menu.promo.title' => ['required', 'string', 'max:160'], 'medical_areas_mega_menu.promo.body' => ['nullable', 'string', 'max:2000'], 'medical_areas_mega_menu.promo.cta_label' => ['required', 'string', 'max:80']]);
        abort_unless($this->hasExactKeys($data['medical_areas_mega_menu'], ['specialization_ids', 'promo']) && $this->hasExactKeys($data['medical_areas_mega_menu']['promo'], ['eyebrow', 'title', 'body', 'cta_label']), 422, 'La configurazione del mega menu Aree mediche non è valida.');
        $navigation = $this->initializer->initialize();
        $configuration = $this->projection->configuration($navigation);
        $configuration['medical_areas_mega_menu'] = $data['medical_areas_mega_menu'];
        $navigation->update(['configuration' => $configuration]);

        return response()->json(['data' => $this->projection->admin($navigation->refresh(), $request)]);
    }

    public function updateFooter(Request $request)
    {
        $this->rejectUnknown($request, ['footer']);
        $data = $request->validate(['footer' => ['required', 'array'], 'footer.brand_description' => ['required', 'string', 'max:2000'], 'footer.booking_label' => ['required', 'string', 'max:80'], 'footer.columns' => ['required', 'array', 'size:3']]);
        abort_unless($this->hasExactKeys($data['footer'], ['brand_description', 'booking_label', 'columns']) && $this->hasExactKeys($data['footer']['columns'], array_keys(SiteNavigationRegistry::FOOTER_COLUMNS)), 422, 'Le colonne Footer sono fisse.');
        foreach (SiteNavigationRegistry::FOOTER_COLUMNS as $key => $definition) {
            $column = $data['footer']['columns'][$key] ?? null;
            abort_unless(is_array($column) && $this->hasExactKeys($column, ['key', 'title', 'items']) && $column['key'] === $key && is_string($column['title'] ?? null) && is_array($column['items'] ?? null), 422, 'La colonna Footer non è valida.');
            $targets = array_column($column['items'], 'target');
            abort_unless(count($targets) === count($definition['targets']) && count(array_unique($targets)) === count($targets) && count(array_diff($targets, $definition['targets'])) === 0 && count(array_diff($definition['targets'], $targets)) === 0, 422, 'Un target non appartiene a questa colonna Footer.');
            foreach ($column['items'] as $item) {
                abort_unless($this->hasExactKeys($item, ['target', 'is_active', 'label']) && is_bool($item['is_active'] ?? null) && (is_null($item['label'] ?? null) || is_string($item['label'])), 422, 'Un elemento Footer non è valido.');
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
}
