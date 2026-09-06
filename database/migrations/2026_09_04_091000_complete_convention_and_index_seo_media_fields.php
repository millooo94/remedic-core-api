<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convention_partner_web_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('convention_partner_web_profiles', 'title')) {
                $table->string('title')->nullable()->after('convention_partner_id');
            }
            if (! Schema::hasColumn('convention_partner_web_profiles', 'public_slug')) {
                $table->string('public_slug')->nullable()->unique()->after('title');
            }
        });

        Schema::table('site_index_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('site_index_pages', 'og_image_path')) {
                $table->string('og_image_path', 2048)->nullable()->after('robots');
            }
            if (! Schema::hasColumn('site_index_pages', 'twitter_title')) {
                $table->string('twitter_title')->nullable()->after('og_image_path');
            }
            if (! Schema::hasColumn('site_index_pages', 'twitter_description')) {
                $table->text('twitter_description')->nullable()->after('twitter_title');
            }
            if (! Schema::hasColumn('site_index_pages', 'twitter_image_path')) {
                $table->string('twitter_image_path', 2048)->nullable()->after('twitter_description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('convention_partner_web_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('convention_partner_web_profiles', 'public_slug')) {
                $table->dropUnique('convention_partner_web_profiles_public_slug_unique');
                $table->dropColumn('public_slug');
            }
            if (Schema::hasColumn('convention_partner_web_profiles', 'title')) {
                $table->dropColumn('title');
            }
        });

        Schema::table('site_index_pages', function (Blueprint $table): void {
            $columns = array_values(array_filter(['og_image_path', 'twitter_title', 'twitter_description', 'twitter_image_path'], fn (string $column): bool => Schema::hasColumn('site_index_pages', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
