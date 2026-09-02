<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\ConsentCategory;
use App\Models\ConsentConfigurationVersion;
use App\Models\ContentTranslation;
use App\Models\SiteSetting;
use App\Services\ConsentCategoryInitializer;
use App\Services\ConsentConfigurationInitializer;
use App\Services\SiteNavigationProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsentConfigurationController extends Controller
{
    public function __construct(
        private readonly ConsentConfigurationInitializer $initializer,
        private readonly ConsentCategoryInitializer $categories,
        private readonly SiteNavigationProjectionService $navigation,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->projection($this->initializer->initialize())]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->rejectUnknown($request, ['is_enabled', 'categories']);
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'categories' => ['sometimes', 'array'],
            'categories.*.key' => ['required', 'in:necessary,preferences,statistics,marketing'],
            'categories.*.translations' => ['required', 'array'],
            'categories.*.translations.*.locale' => ['required', 'in:it,en,es,fr'],
            'categories.*.translations.*.label' => ['nullable', 'string', 'max:255'],
            'categories.*.translations.*.description' => ['nullable', 'string'],
        ]);
        $configuration = DB::transaction(function () use ($validated) {
            $configuration = $this->initializer->initialize();
            $configuration->update(['is_enabled' => $validated['is_enabled']]);
            $this->saveCategories($validated['categories'] ?? []);

            return $configuration->refresh();
        });
        $this->synchronizeLegacyFlags((bool) $configuration->is_enabled);

        return response()->json(['data' => $this->projection($configuration)]);
    }

    public function publishNewVersion(Request $request): JsonResponse
    {
        $this->rejectUnknown($request, []);
        $configuration = DB::transaction(function () {
            $configuration = $this->initializer->initialize();
            $next = $configuration->configuration_version + 1;
            ConsentConfigurationVersion::query()->firstOrCreate(['configuration_version' => $next], ['snapshot' => $this->snapshot($next), 'published_at' => now()]);
            $configuration->update(['configuration_version' => $next]);

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
            'locales' => collect(['it', 'en', 'es', 'fr'])->map(fn (string $locale) => ['locale' => $locale, 'status' => $this->localeComplete($locale) ? 'published' : 'missing'])->all(),
        ];
    }

    private function localeComplete(string $locale): bool
    {
        return $this->categories->initialize()->every(fn (ConsentCategory $category) => $locale === 'it' ? filled($category->name) && filled($category->description) : $category->translations()->where('locale', $locale)->where('publication_state', 'published')->whereNotNull('label')->whereNotNull('description')->exists());
    }

    private function snapshot(int $version): array
    {
        return ['configuration_version' => $version, 'published_at' => now()->toISOString(), 'is_enabled' => (bool) $this->initializer->initialize()->is_enabled, 'locales' => collect(['it', 'en', 'es', 'fr'])->mapWithKeys(fn (string $locale) => [$locale => $this->categories->initialize()->map(fn (ConsentCategory $category) => ['key' => $category->key, 'label' => $locale === 'it' ? $category->name : $category->translations()->where('locale', $locale)->value('label'), 'description' => $locale === 'it' ? $category->description : $category->translations()->where('locale', $locale)->value('description')])->all()])->all(), 'privacy' => $this->navigation->target('privacy'), 'cookie_policy' => $this->navigation->target('cookie_policy')];
    }

    /** @return list<array{id: int, key: string, label: string, description: string, required: bool, translations: list<array{locale: string, label: ?string, description: ?string, status: string}>}> */
    private function categories(): array
    {
        return $this->categories->initialize()->each(fn (ConsentCategory $category) => $category->load('translations'))->map(function (ConsentCategory $category): array {
            return [
                'id' => $category->id,
                'key' => $category->key,
                'label' => $category->name,
                'description' => $category->description,
                'required' => (bool) $category->is_required,
                'translations' => $category->translations->map(fn (ContentTranslation $translation): array => [
                    'locale' => $translation->locale->value,
                    'label' => $translation->label,
                    'description' => $translation->description,
                    'status' => $translation->needsReview() ? 'needs_review' : $translation->publication_state,
                ])->values()->all(),
            ];
        })->all();
    }

    /** @param list<array{key: string, translations: list<array{locale: string, label: ?string, description: ?string}>}> $updates */
    private function saveCategories(array $updates): void
    {
        $categories = $this->categories->initialize()->keyBy('key');

        foreach ($updates as $update) {
            /** @var ConsentCategory $category */
            $category = $categories->get($update['key']);
            foreach ($update['translations'] as $copy) {
                $locale = SupportedLocale::from($copy['locale']);
                if ($locale === SupportedLocale::IT) {
                    abort_unless(filled($copy['label']) && filled($copy['description']), 422, 'Label e descrizione italiane sono obbligatorie.');
                    $category->update(['name' => $copy['label'], 'description' => $copy['description']]);

                    continue;
                }

                $sourceRevision = $category->translations()->where('locale', SupportedLocale::IT->value)->value('source_revision');
                $category->translations()->updateOrCreate(['locale' => $locale->value], [
                    'label' => $copy['label'],
                    'description' => $copy['description'],
                    'publication_state' => 'published',
                    'source_revision' => $sourceRevision,
                    'reviewed_source_revision' => $sourceRevision,
                ]);
            }
        }
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
