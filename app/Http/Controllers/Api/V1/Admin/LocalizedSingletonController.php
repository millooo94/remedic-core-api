<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\GlobalSeoTranslation;
use App\Models\SiteIndexPage;
use App\Models\SiteIndexPageTranslation;
use App\Models\SiteNavigation;
use App\Models\SiteNavigationTranslation;
use App\Models\SitePopup;
use App\Models\SitePopupTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Translation adapter for singleton-like editors, including independent index pages. */
class LocalizedSingletonController extends Controller
{
    public function show(string $kind, int $id, string $locale): JsonResponse
    {
        $item = $this->query($kind, $id)->where('locale', $this->locale($locale)->value)->first();

        return response()->json(['data' => $this->payload($item)]);
    }

    public function store(string $kind, int $id, string $locale): JsonResponse
    {
        $locale = $this->locale($locale);
        if ($locale === SupportedLocale::IT) {
            throw ValidationException::withMessages(['locale' => 'Italiano gestito dal backfill.']);
        }

        $attributes = [];
        // Global SEO translations do not carry a source-revision column. They
        // are independently editable fallbacks, unlike translated singletons.
        if ($kind !== 'seo') {
            $attributes['source_revision'] = $this->sourceRevision($kind, $id);
        }
        $item = $this->query($kind, $id)->where('locale', $locale->value)->first() ?? $this->create($kind, $id, [...$attributes, 'locale' => $locale->value]);

        return response()->json(['data' => $this->payload($item)], 201);
    }

    public function update(Request $request, string $kind, int $id, string $locale): JsonResponse
    {
        $locale = $this->locale($locale);
        if ($locale === SupportedLocale::IT) {
            throw ValidationException::withMessages(['locale' => 'Italiano gestito dall\'editor principale.']);
        }

        $data = $request->validate($this->rules($kind));
        $item = $this->query($kind, $id)->where('locale', $locale->value)->first();
        $created = $item === null;
        if ($item === null) {
            $attributes = ['locale' => $locale->value];
            if ($kind !== 'seo') {
                $attributes['source_revision'] = $this->sourceRevision($kind, $id);
            }
            $item = $this->create($kind, $id, $attributes);
        }

        $item->fill($data);
        if ($kind !== 'seo') {
            $source = $this->sourceRevision($kind, $id);
            $item->forceFill([
                'source_revision' => $source,
                'reviewed_source_revision' => $source,
            ]);
        }
        $item->save();

        return response()->json(['data' => $this->payload($item->refresh())], $created ? 201 : 200);
    }

    private function query(string $kind, int $id): Builder
    {
        return match ($kind) {
            'index-pages' => SiteIndexPageTranslation::query()->where('site_index_page_id', $id),
            'navigation' => SiteNavigationTranslation::query()->where('site_navigation_id', $id),
            'popup' => SitePopupTranslation::query()->where('site_popup_id', $id),
            // The supplied id is a stable UI adapter id, never the translation PK.
            'seo' => GlobalSeoTranslation::query(),
            default => abort(404),
        };
    }

    private function rules(string $kind): array
    {
        return match ($kind) {
            'index-pages' => ['title' => ['nullable', 'string'], 'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'content' => ['nullable', 'array'], 'seo_title' => ['nullable', 'string'], 'seo_description' => ['nullable', 'string'], 'seo_h1' => ['nullable', 'string']],
            'navigation' => ['configuration' => ['nullable', 'array']],
            'popup' => ['eyebrow' => ['nullable', 'string'], 'title' => ['nullable', 'string'], 'body' => ['nullable', 'string'], 'primary_cta_label' => ['nullable', 'string'], 'secondary_cta_label' => ['nullable', 'string']],
            'seo' => ['default_meta_title' => ['nullable', 'string'], 'default_meta_description' => ['nullable', 'string']],
        };
    }

    private function sourceRevision(string $kind, int $id): ?string
    {
        return match ($kind) {
            'index-pages' => SiteIndexPage::query()->findOrFail($id)->translations()->where('locale', 'it')->value('source_revision'),
            'navigation' => SiteNavigation::query()->findOrFail($id)->translations()->where('locale', 'it')->value('source_revision'),
            'popup' => SitePopup::query()->findOrFail($id)->translations()->where('locale', 'it')->value('source_revision'),
            default => null,
        };
    }

    /** @param array<string, mixed> $attributes */
    private function create(string $kind, int $id, array $attributes): Model
    {
        return match ($kind) {
            'index-pages' => SiteIndexPage::query()->findOrFail($id)->translations()->create($attributes),
            'navigation' => SiteNavigation::query()->findOrFail($id)->translations()->create($attributes),
            'popup' => SitePopup::query()->findOrFail($id)->translations()->create($attributes),
            'seo' => GlobalSeoTranslation::query()->create($attributes),
            default => abort(404),
        };
    }

    private function locale(string $value): SupportedLocale
    {
        return SupportedLocale::tryFrom($value) ?? throw ValidationException::withMessages(['locale' => 'Locale non supportato.']);
    }

    private function payload(?Model $item): array
    {
        $status = $item === null ? 'missing' : ($this->isComplete($item) ? 'available' : 'incomplete');

        return ['status' => $status, 'translation' => $item];
    }

    private function isComplete(Model $item): bool
    {
        return match (true) {
            $item instanceof SiteIndexPageTranslation => filled($item->title) && filled($item->slug),
            $item instanceof SitePopupTranslation => filled($item->title),
            $item instanceof GlobalSeoTranslation => filled($item->default_meta_title),
            default => true,
        };
    }
}
