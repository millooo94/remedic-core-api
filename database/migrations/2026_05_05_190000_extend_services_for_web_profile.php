<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            if (! Schema::hasColumn('services', 'short_description')) {
                $table->text('short_description')->nullable()->after('description');
            }

            if (! Schema::hasColumn('services', 'intro_text')) {
                $table->text('intro_text')->nullable()->after('short_description');
            }

            if (! Schema::hasColumn('services', 'local_intro_text')) {
                $table->text('local_intro_text')->nullable()->after('intro_text');
            }

            if (! Schema::hasColumn('services', 'local_area_notes')) {
                $table->text('local_area_notes')->nullable()->after('local_intro_text');
            }

            if (! Schema::hasColumn('services', 'preparation_notes')) {
                $table->text('preparation_notes')->nullable()->after('local_area_notes');
            }

            if (! Schema::hasColumn('services', 'duration_text')) {
                $table->string('duration_text')->nullable()->after('preparation_notes');
            }

            if (! Schema::hasColumn('services', 'price_text')) {
                $table->string('price_text')->nullable()->after('duration_text');
            }

            if (! Schema::hasColumn('services', 'exam_report_time')) {
                $table->string('exam_report_time')->nullable()->after('price_text');
            }

            if (! Schema::hasColumn('services', 'featured_image_path')) {
                $table->string('featured_image_path')->nullable()->after('exam_report_time');
            }

            if (! Schema::hasColumn('services', 'social_image_path')) {
                $table->string('social_image_path')->nullable()->after('featured_image_path');
            }

            if (! Schema::hasColumn('services', 'is_diagnostic')) {
                $table->boolean('is_diagnostic')->default(false)->after('social_image_path')->index();
            }

            if (! Schema::hasColumn('services', 'is_visit')) {
                $table->boolean('is_visit')->default(false)->after('is_diagnostic')->index();
            }

            if (! Schema::hasColumn('services', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_visit')->index();
            }

            if (! Schema::hasColumn('services', 'is_local_seo_enabled')) {
                $table->boolean('is_local_seo_enabled')->default(true)->after('is_featured');
            }

            if (! Schema::hasColumn('services', 'seo_title')) {
                $table->string('seo_title')->nullable()->after('is_local_seo_enabled');
            }

            if (! Schema::hasColumn('services', 'local_seo_title')) {
                $table->string('local_seo_title')->nullable()->after('seo_title');
            }

            if (! Schema::hasColumn('services', 'seo_description')) {
                $table->text('seo_description')->nullable()->after('local_seo_title');
            }

            if (! Schema::hasColumn('services', 'local_seo_description')) {
                $table->text('local_seo_description')->nullable()->after('seo_description');
            }

            if (! Schema::hasColumn('services', 'seo_h1')) {
                $table->string('seo_h1')->nullable()->after('local_seo_description');
            }

            if (! Schema::hasColumn('services', 'local_seo_h1')) {
                $table->string('local_seo_h1')->nullable()->after('seo_h1');
            }

            if (! Schema::hasColumn('services', 'canonical_url')) {
                $table->string('canonical_url')->nullable()->after('local_seo_h1');
            }

            if (! Schema::hasColumn('services', 'robots')) {
                $table->string('robots')->default('index,follow')->after('canonical_url');
            }

            if (! Schema::hasColumn('services', 'og_title')) {
                $table->string('og_title')->nullable()->after('robots');
            }

            if (! Schema::hasColumn('services', 'og_description')) {
                $table->text('og_description')->nullable()->after('og_title');
            }

            if (! Schema::hasColumn('services', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('og_description')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $columns = [
                'short_description',
                'intro_text',
                'local_intro_text',
                'local_area_notes',
                'preparation_notes',
                'duration_text',
                'price_text',
                'exam_report_time',
                'featured_image_path',
                'social_image_path',
                'is_diagnostic',
                'is_visit',
                'is_featured',
                'is_local_seo_enabled',
                'seo_title',
                'local_seo_title',
                'seo_description',
                'local_seo_description',
                'seo_h1',
                'local_seo_h1',
                'canonical_url',
                'robots',
                'og_title',
                'og_description',
                'sort_order',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('services', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
