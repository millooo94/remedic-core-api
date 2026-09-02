<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_public_profiles', function (Blueprint $table): void {
            $table->string('local_seo_title')->nullable()->after('seo_h1');
            $table->text('local_seo_description')->nullable()->after('local_seo_title');
            $table->string('local_seo_h1')->nullable()->after('local_seo_description');
            $table->boolean('is_local_seo_enabled')->default(true)->after('local_seo_h1');
        });
    }

    public function down(): void
    {
        Schema::table('professional_public_profiles', function (Blueprint $table): void {
            $table->dropColumn(['local_seo_title', 'local_seo_description', 'local_seo_h1', 'is_local_seo_enabled']);
        });
    }
};
