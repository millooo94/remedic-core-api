<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MASTER_OWNER = 'App\\Models\\Service';

    public function up(): void
    {
        $contentColumns = [
            'description', 'short_description', 'intro_text', 'local_intro_text',
            'local_area_notes', 'preparation_notes', 'exam_report_time',
            'seo_title', 'local_seo_title', 'seo_description', 'local_seo_description',
            'seo_h1', 'local_seo_h1', 'canonical_url', 'og_title', 'og_description',
        ];

        DB::table('services')->orderBy('id')->each(function (object $service) use ($contentColumns): void {
            $hasText = collect($contentColumns)->contains(
                fn (string $column): bool => trim((string) ($service->{$column} ?? '')) !== ''
            );
            $hasNonDefaultSeo = ($service->robots ?? 'index,follow') !== 'index,follow'
                || ! (bool) ($service->is_local_seo_enabled ?? true);
            $hasSections = DB::table('sections')
                ->where('sectionable_type', self::MASTER_OWNER)
                ->where('sectionable_id', $service->id)
                ->exists();
            $hasFaqs = DB::table('faq_items')
                ->where('faqable_type', self::MASTER_OWNER)
                ->where('faqable_id', $service->id)
                ->exists();

            if (! $hasText && ! $hasNonDefaultSeo && ! $hasSections && ! $hasFaqs) {
                return;
            }

            DB::table('service_web_profiles')->updateOrInsert(
                ['service_id' => $service->id],
                [
                    'public_slug' => $service->slug,
                    'short_description' => $service->short_description,
                    'is_web_enabled' => false,
                    'list_sort_order' => (int) ($service->sort_order ?? 0),
                    'seo_title' => $service->seo_title,
                    'local_seo_title' => $service->local_seo_title,
                    'seo_description' => $service->seo_description,
                    'local_seo_description' => $service->local_seo_description,
                    'seo_h1' => $service->seo_h1,
                    'local_seo_h1' => $service->local_seo_h1,
                    'is_local_seo_enabled' => (bool) ($service->is_local_seo_enabled ?? true),
                    'canonical_url' => $service->canonical_url,
                    'robots' => $service->robots ?: 'index,follow',
                    'og_title' => $service->og_title,
                    'og_description' => $service->og_description,
                    'legacy_content' => json_encode([
                        'description' => $service->description,
                        'intro_text' => $service->intro_text,
                        'local_intro_text' => $service->local_intro_text,
                        'local_area_notes' => $service->local_area_notes,
                        'preparation_notes' => $service->preparation_notes,
                        'exam_report_time' => $service->exam_report_time,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });
    }

    public function down(): void
    {
        // Additive preservation: rollback must not guess which profiles were edited later.
    }
};
