<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_web_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('service_web_profiles', 'is_diagnostic')) {
                $table->boolean('is_diagnostic')->default(false)->index()->after('is_web_enabled');
            }
            if (! Schema::hasColumn('service_web_profiles', 'is_aesthetic_medicine')) {
                $table->boolean('is_aesthetic_medicine')->default(false)->index()->after('is_diagnostic');
            }
        });

        Schema::table('blog_posts', function (Blueprint $table): void {
            if (! Schema::hasColumn('blog_posts', 'content_type')) {
                $table->string('content_type')->nullable()->index()->after('category_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            if (Schema::hasColumn('blog_posts', 'content_type')) {
                $table->dropIndex(['content_type']);
                $table->dropColumn('content_type');
            }
        });
        Schema::table('service_web_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('service_web_profiles', 'is_aesthetic_medicine')) {
                $table->dropIndex(['is_aesthetic_medicine']);
                $table->dropColumn('is_aesthetic_medicine');
            }
            if (Schema::hasColumn('service_web_profiles', 'is_diagnostic')) {
                $table->dropIndex(['is_diagnostic']);
                $table->dropColumn('is_diagnostic');
            }
        });
    }
};
