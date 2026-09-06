<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['professional_public_profiles', 'service_web_profiles', 'blog_posts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'og_image_path')) {
                    $table->string('og_image_path', 2048)->nullable()->after('og_description');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['professional_public_profiles', 'service_web_profiles', 'blog_posts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'og_image_path')) {
                    $table->dropColumn('og_image_path');
                }
            });
        }
    }
};
