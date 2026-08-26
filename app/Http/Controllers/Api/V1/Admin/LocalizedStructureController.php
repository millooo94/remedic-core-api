<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use App\Models\FaqItemTranslation;
use App\Models\Section;
use App\Models\SectionTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Keeps order/key ownership on the shared structural record. */
class LocalizedStructureController extends Controller
{
    public function show(string $kind, int $id, string $locale): JsonResponse
    {
        [$owner, $class, $foreignKey] = $this->definition($kind, $id);

        return response()->json(['data' => $class::query()->where($foreignKey, $owner->id)->where('locale', $this->locale($locale)->value)->first()]);
    }

    public function store(string $kind, int $id, string $locale): JsonResponse
    {
        [$owner, $class, $foreignKey] = $this->definition($kind, $id);
        $locale = $this->locale($locale);
        if ($locale === SupportedLocale::IT) {
            throw ValidationException::withMessages(['locale' => 'Italiano gestito dal backfill.']);
        }
        $translation = $class::query()->firstOrCreate([$foreignKey => $owner->id, 'locale' => $locale->value]);

        return response()->json(['data' => $translation], 201);
    }

    public function update(Request $request, string $kind, int $id, string $locale): JsonResponse
    {
        [$owner, $class, $foreignKey] = $this->definition($kind, $id);
        $locale = $this->locale($locale);
        $translation = $class::query()->where($foreignKey, $owner->id)->where('locale', $locale->value)->firstOrFail();
        $translation->fill($kind === 'sections'
            ? $request->validate(['title' => ['nullable', 'string'], 'subtitle' => ['nullable', 'string'], 'content' => ['nullable', 'string']])
            : $request->validate(['question' => ['nullable', 'string'], 'answer' => ['nullable', 'string']]))->save();

        return response()->json(['data' => $translation]);
    }

    private function definition(string $kind, int $id): array
    {
        return match ($kind) {
            'sections' => [Section::query()->findOrFail($id), SectionTranslation::class, 'section_id'],
            'faqs' => [FaqItem::query()->findOrFail($id), FaqItemTranslation::class, 'faq_item_id'],
            default => abort(404),
        };
    }

    private function locale(string $locale): SupportedLocale
    {
        return SupportedLocale::tryFrom($locale) ?? throw ValidationException::withMessages(['locale' => 'Locale non supportato.']);
    }
}
