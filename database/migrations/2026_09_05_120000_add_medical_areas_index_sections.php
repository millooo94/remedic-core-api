<?php

use App\Models\SiteIndexPage;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $index = SiteIndexPage::query()->where('internal_key', 'medical_areas_index')->first();
        if ($index === null) {
            return;
        }

        foreach (['hero' => 'Hero / introduzione', 'specialties_catalog' => 'Elenco specialità'] as $sortOrder => $internalTitle) {
            $section = $index->sections()->firstOrNew(['key' => $sortOrder]);
            $section->fill([
                'internal_title' => $section->internal_title ?: $internalTitle,
                'title' => $section->title ?: $internalTitle,
                'content' => $section->content ?? '',
                'sort_order' => array_search($sortOrder, ['hero', 'specialties_catalog'], true),
                'is_active' => $section->exists ? $section->is_active : true,
            ])->save();
        }

        $index->sections()->whereNotIn('key', ['hero', 'specialties_catalog'])->delete();
    }

    public function down(): void
    {
        $index = SiteIndexPage::query()->where('internal_key', 'medical_areas_index')->first();
        $index?->sections()->whereIn('key', ['hero', 'specialties_catalog'])->delete();
    }
};
