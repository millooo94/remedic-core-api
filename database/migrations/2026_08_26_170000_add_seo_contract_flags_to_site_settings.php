<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('site_settings', 'seo_indexing_enabled')) {
                $table->boolean('seo_indexing_enabled')->default(true)->after('default_og_image_path');
            }
            if (! Schema::hasColumn('site_settings', 'seo_sitemap_enabled')) {
                $table->boolean('seo_sitemap_enabled')->default(true)->after('seo_indexing_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('site_settings', 'seo_sitemap_enabled')) {
                $table->dropColumn('seo_sitemap_enabled');
            }
            if (Schema::hasColumn('site_settings', 'seo_indexing_enabled')) {
                $table->dropColumn('seo_indexing_enabled');
            }
        });
    }
};
