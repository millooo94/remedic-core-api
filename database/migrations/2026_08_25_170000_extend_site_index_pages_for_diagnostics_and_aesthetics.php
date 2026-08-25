<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_index_pages', function (Blueprint $table): void {
            $table->json('configuration')->nullable()->after('content');
            $table->string('hero_video_path')->nullable()->after('configuration');
            $table->string('hero_poster_path')->nullable()->after('hero_video_path');
            $table->string('intro_split_image_path')->nullable()->after('hero_poster_path');
        });
        Schema::table('service_web_profiles', function (Blueprint $table): void {
            $table->string('aesthetic_category')->nullable()->after('is_aesthetic_medicine');
        });
    }

    public function down(): void
    {
        Schema::table('service_web_profiles', fn (Blueprint $table) => $table->dropColumn('aesthetic_category'));
        Schema::table('site_index_pages', fn (Blueprint $table) => $table->dropColumn(['configuration', 'hero_video_path', 'hero_poster_path', 'intro_split_image_path']));
    }
};
