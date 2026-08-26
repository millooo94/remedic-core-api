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

        $item = $this->query($kind, $id)->firstOrCreate(['locale' => $locale->value], [
            'publication_state' => 'draft',
            'source_revision' => $this->sourceRevision($kind, $id),
        ]);

        return response()->json(['data' => $this->payload($item)], 201);
    }

    public function update(Request $request, string $kind, int $id, string $locale): JsonResponse
    {
        $item = $this->query($kind, $id)->where('locale', $this->locale($locale)->value)->firstOrFail();
        $item->fill($request->validate($this->rules($kind)))->save();

        return response()->json(['data' => $this->payload($item->refresh())]);
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
            'index-pages' => ['title' => ['nullable', 'string'], 'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'content' => ['nullable', 'array'], 'seo_title' => ['nullable', 'string'], 'seo_description' => ['nullable', 'string'], 'seo_h1' => ['nullable', 'string'], 'publication_state' => ['nullable', 'in:draft,published']],
            'navigation' => ['configuration' => ['nullable', 'array'], 'publication_state' => ['nullable', 'in:draft,published']],
            'popup' => ['eyebrow' => ['nullable', 'string'], 'title' => ['nullable', 'string'], 'body' => ['nullable', 'string'], 'primary_cta_label' => ['nullable', 'string'], 'secondary_cta_label' => ['nullable', 'string'], 'publication_state' => ['nullable', 'in:draft,published']],
            'seo' => ['default_meta_title' => ['nullable', 'string'], 'default_meta_description' => ['nullable', 'string'], 'publication_state' => ['nullable', 'in:draft,published']],
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

    private function locale(string $value): SupportedLocale
    {
        return SupportedLocale::tryFrom($value) ?? throw ValidationException::withMessages(['locale' => 'Locale non supportato.']);
    }

    private function payload(?Model $item): array
    {
        $status = $item === null ? 'missing' : (($item->source_revision ?? null) !== ($item->reviewed_source_revision ?? null) && $item->locale !== SupportedLocale::IT ? 'needs_review' : ($item->publication_state ?? 'draft'));

        return ['status' => $status, 'translation' => $item];
    }
}
