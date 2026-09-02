<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_web_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_web_profiles', 'twitter_title')) {
                $table->string('twitter_title')->nullable()->after('og_description');
            }
            if (! Schema::hasColumn('service_web_profiles', 'twitter_description')) {
                $table->text('twitter_description')->nullable()->after('twitter_title');
            }
            if (! Schema::hasColumn('service_web_profiles', 'twitter_image_path')) {
                $table->string('twitter_image_path', 2048)->nullable()->after('twitter_description');
            }
        });

        Schema::table('content_translations', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_translations', 'twitter_title')) {
                $table->string('twitter_title')->nullable()->after('og_description');
            }
            if (! Schema::hasColumn('content_translations', 'twitter_description')) {
                $table->text('twitter_description')->nullable()->after('twitter_title');
            }
            if (! Schema::hasColumn('content_translations', 'local_seo_title')) {
                $table->string('local_seo_title')->nullable()->after('twitter_description');
            }
            if (! Schema::hasColumn('content_translations', 'local_seo_description')) {
                $table->text('local_seo_description')->nullable()->after('local_seo_title');
            }
            if (! Schema::hasColumn('content_translations', 'local_seo_h1')) {
                $table->string('local_seo_h1')->nullable()->after('local_seo_description');
            }
        });

        DB::table('pages')->orderBy('id')->each(function (object $page): void {
            DB::table('content_translations')
                ->where('translatable_type', 'App\\Models\\Page')
                ->where('translatable_id', $page->id)
                ->where('locale', 'it')
                ->update([
                    'twitter_title' => $page->twitter_title,
                    'twitter_description' => $page->twitter_description,
                    'updated_at' => now(),
                ]);
        });

        DB::table('service_web_profiles')->orderBy('id')->each(function (object $profile): void {
            DB::table('content_translations')
                ->where('translatable_type', 'App\\Models\\ServiceWebProfile')
                ->where('translatable_id', $profile->id)
                ->where('locale', 'it')
                ->update([
                    'twitter_title' => $profile->twitter_title,
                    'twitter_description' => $profile->twitter_description,
                    'local_seo_title' => $profile->local_seo_title,
                    'local_seo_description' => $profile->local_seo_description,
                    'local_seo_h1' => $profile->local_seo_h1,
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $columns = collect(['twitter_title', 'twitter_description', 'local_seo_title', 'local_seo_description', 'local_seo_h1'])
                ->filter(fn (string $column): bool => Schema::hasColumn('content_translations', $column))
                ->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('service_web_profiles', function (Blueprint $table): void {
            $columns = collect(['twitter_title', 'twitter_description', 'twitter_image_path'])
                ->filter(fn (string $column): bool => Schema::hasColumn('service_web_profiles', $column))
                ->all();
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
