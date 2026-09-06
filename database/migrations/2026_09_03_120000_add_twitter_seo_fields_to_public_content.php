<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['professional_public_profiles', 'specialization_web_profiles', 'checkup_web_profiles', 'blog_posts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'twitter_title')) {
                    $table->string('twitter_title')->nullable()->after('og_description');
                }
                if (! Schema::hasColumn($tableName, 'twitter_description')) {
                    $table->text('twitter_description')->nullable()->after('twitter_title');
                }
                if (! Schema::hasColumn($tableName, 'twitter_image_path')) {
                    $table->string('twitter_image_path', 2048)->nullable()->after('twitter_description');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['professional_public_profiles', 'specialization_web_profiles', 'checkup_web_profiles', 'blog_posts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $columns = array_values(array_filter(['twitter_title', 'twitter_description', 'twitter_image_path'], fn (string $column): bool => Schema::hasColumn($tableName, $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
