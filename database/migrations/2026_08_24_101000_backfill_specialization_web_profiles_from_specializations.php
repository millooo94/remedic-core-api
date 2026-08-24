<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OWNER = 'App\\Models\\Specialization';

    public function up(): void
    {
        $contentColumns = [
            'short_description', 'intro_text', 'local_intro_text', 'local_area_notes',
            'seo_title', 'local_seo_title', 'seo_description', 'local_seo_description',
            'seo_h1', 'local_seo_h1', 'canonical_url', 'og_title', 'og_description',
        ];

        DB::table('specializations')->orderBy('id')->each(function (object $specialization) use ($contentColumns): void {
            $hasText = collect($contentColumns)->contains(
                fn (string $column): bool => trim((string) ($specialization->{$column} ?? '')) !== ''
            );
            $hasSections = DB::table('sections')
                ->where('sectionable_type', self::OWNER)
                ->where('sectionable_id', $specialization->id)
                ->exists();
            $hasFaqs = DB::table('faq_items')
                ->where('faqable_type', self::OWNER)
                ->where('faqable_id', $specialization->id)
                ->exists();

            if (! $hasText && ! $hasSections && ! $hasFaqs) {
                return;
            }

            DB::table('specialization_web_profiles')->updateOrInsert(
                ['specialization_id' => $specialization->id],
                [
                    'slug' => $specialization->slug,
                    'short_description' => $specialization->short_description,
                    'is_web_enabled' => (bool) ($specialization->is_web_active ?? false),
                    'list_sort_order' => (int) ($specialization->sort_order ?? 0),
                    'seo_title' => $specialization->seo_title,
                    'local_seo_title' => $specialization->local_seo_title,
                    'seo_description' => $specialization->seo_description,
                    'local_seo_description' => $specialization->local_seo_description,
                    'seo_h1' => $specialization->seo_h1,
                    'local_seo_h1' => $specialization->local_seo_h1,
                    'is_local_seo_enabled' => (bool) ($specialization->is_local_seo_enabled ?? true),
                    'canonical_url' => $specialization->canonical_url,
                    'robots' => $specialization->robots ?: 'index,follow',
                    'og_title' => $specialization->og_title,
                    'og_description' => $specialization->og_description,
                    'legacy_content' => json_encode([
                        'intro_text' => $specialization->intro_text,
                        'local_intro_text' => $specialization->local_intro_text,
                        'local_area_notes' => $specialization->local_area_notes,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });
    }

    public function down(): void
    {
        // The profile is an additive preservation layer. A rollback must not guess
        // whether a row was created by the backfill or later by an editor.
    }
};
