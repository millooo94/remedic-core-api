<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\ConsentConfigurationInitializer;
use App\Services\SiteNavigationProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentConfigurationController extends Controller
{
    public function __construct(
        private readonly ConsentConfigurationInitializer $initializer,
        private readonly SiteNavigationProjectionService $navigation,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->projection($this->initializer->initialize())]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->rejectUnknown($request, ['is_enabled']);
        $validated = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $configuration = $this->initializer->initialize();
        $configuration->update(['is_enabled' => $validated['is_enabled']]);
        $this->synchronizeLegacyFlags((bool) $configuration->is_enabled);

        return response()->json(['data' => $this->projection($configuration->refresh())]);
    }

    public function publishNewVersion(Request $request): JsonResponse
    {
        $this->rejectUnknown($request, []);
        $configuration = DB::transaction(function () {
            $configuration = $this->initializer->initialize();
            $configuration->increment('configuration_version');

            return $configuration->refresh();
        });

        return response()->json(['data' => $this->projection($configuration)]);
    }

    /** @return array<string, mixed> */
    private function projection(object $configuration): array
    {
        return [
            'is_enabled' => (bool) $configuration->is_enabled,
            'configuration_version' => (int) $configuration->configuration_version,
            'categories' => $this->categories(),
            'privacy' => ['label' => 'Privacy Policy', ...$this->navigation->target('privacy')],
            'cookie_policy' => ['label' => 'Cookie Policy', ...$this->navigation->target('cookie_policy')],
        ];
    }

    /** @return list<array{key: string, label: string, required: bool}> */
    private function categories(): array
    {
        return [
            ['key' => 'necessary', 'label' => 'Necessari', 'required' => true],
            ['key' => 'preferences', 'label' => 'Preferenze', 'required' => false],
            ['key' => 'statistics', 'label' => 'Statistiche', 'required' => false],
            ['key' => 'marketing', 'label' => 'Marketing', 'required' => false],
        ];
    }

    private function synchronizeLegacyFlags(bool $enabled): void
    {
        $settings = SiteSetting::query()->find(1);
        $settings?->update(['cmp_enabled' => $enabled, 'cmp_banner_enabled' => $enabled, 'cmp_consent_mode_enabled' => $enabled]);
    }

    private function rejectUnknown(Request $request, array $allowed): void
    {
        abort_unless(array_diff(array_keys($request->all()), $allowed) === [], 422, 'Il payload non contiene campi consentiti.');
    }
}
