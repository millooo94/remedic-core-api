<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['default_locality_phrase', 'seo_sitemap_enabled']);
        });
        Schema::table('global_seo_translations', function (Blueprint $table) {
            $table->dropColumn('default_social_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('default_locality_phrase')->nullable();
            $table->boolean('seo_sitemap_enabled')->default(true);
        });
        Schema::table('global_seo_translations', function (Blueprint $table) {
            $table->string('default_social_image_path')->nullable();
        });
    }
};
