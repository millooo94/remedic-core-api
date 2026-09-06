<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });

        Schema::table('site_index_pages', function (Blueprint $table): void {
            $table->dropColumn('published_at');
        });

        foreach (['site_index_page_translations', 'site_navigation_translations', 'site_popup_translations', 'global_seo_translations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('publication_state');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable()->index();
        });

        Schema::table('site_index_pages', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable();
        });

        foreach (['site_index_page_translations', 'site_navigation_translations', 'site_popup_translations', 'global_seo_translations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('publication_state', 16)->default('draft');
            });
        }
    }
};
