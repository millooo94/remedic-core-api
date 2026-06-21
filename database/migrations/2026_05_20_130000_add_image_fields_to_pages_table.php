<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('pages', 'hero_image_path')) {
                $table->string('hero_image_path', 2048)->nullable()->after('intro_text');
            }

            if (! Schema::hasColumn('pages', 'hero_image_alt')) {
                $table->string('hero_image_alt')->nullable()->after('hero_image_path');
            }

            if (! Schema::hasColumn('pages', 'og_image_path')) {
                $table->string('og_image_path', 2048)->nullable()->after('og_description');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            foreach (['hero_image_alt', 'hero_image_path', 'og_image_path'] as $column) {
                if (Schema::hasColumn('pages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
