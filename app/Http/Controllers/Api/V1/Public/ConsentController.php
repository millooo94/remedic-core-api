<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\ConsentService as ConsentServiceModel;
use App\Services\ConsentCategoryInitializer;
use App\Services\ConsentConfigurationInitializer;
use App\Services\ConsentService;
use App\Services\LocalizedContentResolver;
use App\Services\PublicLocaleResolver;
use App\Services\SiteNavigationProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    public function __construct(
        private readonly ConsentConfigurationInitializer $initializer,
        private readonly ConsentCategoryInitializer $categories,
        private readonly ConsentService $consents,
        private readonly SiteNavigationProjectionService $navigation,
        private readonly PublicLocaleResolver $locales,
        private readonly LocalizedContentResolver $localized,
    ) {}

    public function configuration(Request $request): JsonResponse
    {
        $configuration = $this->initializer->initialize();
        $locale = $this->locales->resolve($request);

        return response()->json(['data' => [
            'enabled' => (bool) $configuration->is_enabled,
            'configuration_version' => (int) $configuration->configuration_version,
            'categories' => $this->categories($locale),
            'services' => $this->services(),
            'privacy' => ['label' => 'Privacy Policy', ...$this->navigation->target('privacy', $locale)],
            'cookie_policy' => ['label' => 'Cookie Policy', ...$this->navigation->target('cookie_policy', $locale)],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $preferences = $this->preferences($request, true);
        $configuration = $this->initializer->initialize();
        abort_unless($request->integer('configuration_version') === $configuration->configuration_version, 409, 'La configurazione del consenso non è aggiornata.');
        $result = $this->consents->create($configuration, $preferences);

        return response()->json(['data' => [
            ...$this->recordProjection($result['record'], $configuration->configuration_version),
            'management_token' => $result['management_token'],
        ]], 201);
    }

    public function show(string $publicId, Request $request): JsonResponse
    {
        $record = $this->authorizedRecord($publicId, $request);
        $configuration = $this->initializer->initialize();

        return response()->json(['data' => $this->recordProjection($record, $configuration->configuration_version)]);
    }

    public function update(string $publicId, Request $request): JsonResponse
    {
        $record = $this->authorizedRecord($publicId, $request);
        $preferences = $this->preferences($request, true);
        $configuration = $this->initializer->initialize();
        abort_unless($request->integer('configuration_version') === $configuration->configuration_version, 409, 'La configurazione del consenso non è aggiornata.');
        $record = $this->consents->update($record, $configuration, $preferences);

        return response()->json(['data' => $this->recordProjection($record, $configuration->configuration_version)]);
    }

    private function authorizedRecord(string $publicId, Request $request): ConsentRecord
    {
        $token = $request->header('X-Consent-Token');
        abort_unless(is_string($token) && strlen($token) === 64, 403, 'Credenziali consenso non valide.');
        $record = ConsentRecord::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless(hash_equals((string) $record->management_token_hash, hash('sha256', $token)), 403, 'Credenziali consenso non valide.');

        return $record;
    }

    /** @return array{preferences: bool, statistics: bool, marketing: bool} */
    private function preferences(Request $request, bool $requiresVersion): array
    {
        $rules = [
            'preferences' => ['required', 'boolean'],
            'statistics' => ['required', 'boolean'],
            'marketing' => ['required', 'boolean'],
        ];
        if ($requiresVersion) {
            $rules['configuration_version'] = ['required', 'integer', 'min:1'];
        }
        $validated = $request->validate($rules);

        return [
            'preferences' => (bool) $validated['preferences'],
            'statistics' => (bool) $validated['statistics'],
            'marketing' => (bool) $validated['marketing'],
        ];
    }

    /** @return array<string, mixed> */
    private function recordProjection(ConsentRecord $record, int $currentVersion): array
    {
        return [
            'public_id' => $record->public_id,
            'configuration_version' => $record->configuration_version,
            'current_configuration_version' => $currentVersion,
            'necessary' => true,
            'preferences' => (bool) $record->preferences,
            'statistics' => (bool) $record->statistics,
            'marketing' => (bool) $record->marketing,
            'consented_at' => $record->consented_at?->toISOString(),
            'last_updated_at' => $record->last_updated_at?->toISOString(),
            'requires_renewal' => $record->configuration_version !== $currentVersion,
        ];
    }

    /** @return list<array{key: string, label: string, description: string, required: bool}> */
    private function categories(SupportedLocale $locale): array
    {
        return $this->categories->initialize()->map(function ($category) use ($locale): array {
            // A missing optional locale must never make the CMP unavailable:
            // the published Italian source remains the safe runtime fallback.
            $translation = $this->localized->translation($category, $locale)
                ?? $this->localized->translation($category, SupportedLocale::IT);
            abort_if($translation === null, 500, 'Categoria consenso non configurata.');

            return ['key' => $category->key, 'label' => $translation->label, 'description' => $translation->description, 'required' => (bool) $category->is_required];
        })->all();
    }

    /** @return list<array{key: string, name: string, provider: string|null, category: string, type: string, position: string, config: array<string, mixed>}> */
    private function services(): array
    {
        return ConsentServiceModel::query()
            ->active()
            ->with('category:id,key')
            ->ordered()
            ->get()
            ->filter(fn (ConsentServiceModel $service): bool => $service->category !== null)
            ->map(fn (ConsentServiceModel $service): array => [
                'key' => $service->key,
                'name' => $service->name,
                'provider' => $service->provider,
                'category' => $service->category->key,
                'type' => (string) $service->execution_mode->value,
                'position' => (string) ($service->public_config['position'] ?? 'head'),
                'config' => $this->publicServiceConfig($service->public_config ?? []),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $config @return array<string, string> */
    private function publicServiceConfig(array $config): array
    {
        return collect($config)
            ->only(['driver', 'measurement_id', 'container_id', 'pixel_id', 'src'])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->all();
    }
}
