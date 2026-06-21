<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('pages', 'twitter_title')) {
                $table->string('twitter_title')->nullable()->after('og_description');
            }

            if (! Schema::hasColumn('pages', 'twitter_description')) {
                $table->text('twitter_description')->nullable()->after('twitter_title');
            }

            if (! Schema::hasColumn('pages', 'twitter_image_path')) {
                $table->string('twitter_image_path')->nullable()->after('og_image_path');
            }

            if (! Schema::hasColumn('pages', 'meta_author')) {
                $table->string('meta_author')->nullable()->after('twitter_image_path');
            }

            if (! Schema::hasColumn('pages', 'meta_creator')) {
                $table->string('meta_creator')->nullable()->after('meta_author');
            }

            if (! Schema::hasColumn('pages', 'meta_keywords')) {
                $table->text('meta_keywords')->nullable()->after('meta_creator');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            foreach ([
                'twitter_title',
                'twitter_description',
                'twitter_image_path',
                'meta_author',
                'meta_creator',
                'meta_keywords',
            ] as $column) {
                if (Schema::hasColumn('pages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
