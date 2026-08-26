<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_navigations', function (Blueprint $table): void {
            $table->string('medical_areas_mega_menu_promo_image_path')->nullable()->after('center_mega_menu_promo_image_path');
        });
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->string('tiktok_url')->nullable()->after('instagram_url');
            $table->string('youtube_url')->nullable()->after('tiktok_url');
        });
    }

    public function down(): void
    {
        Schema::table('site_navigations', function (Blueprint $table): void {
            $table->dropColumn('medical_areas_mega_menu_promo_image_path');
        });
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->dropColumn(['tiktok_url', 'youtube_url']);
        });
    }
};
