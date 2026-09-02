<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->string('content_kind', 20)->default('standard')->after('template');
            $table->longText('custom_html')->nullable()->after('content_kind');
            $table->longText('custom_css')->nullable()->after('custom_html');
            $table->longText('custom_javascript')->nullable()->after('custom_css');
        });

        Schema::table('content_translations', function (Blueprint $table): void {
            $table->longText('custom_html')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->dropColumn('custom_html');
        });

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropColumn(['content_kind', 'custom_html', 'custom_css', 'custom_javascript']);
        });
    }
};
