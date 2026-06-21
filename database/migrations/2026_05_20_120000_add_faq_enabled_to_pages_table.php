<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages') || Schema::hasColumn('pages', 'faq_enabled')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            $table->boolean('faq_enabled')->default(false)->after('og_description');
            $table->index('faq_enabled');
        });

        DB::table('pages')
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('faq_items')
                    ->whereColumn('faq_items.faqable_id', 'pages.id')
                    ->where('faq_items.faqable_type', 'App\\Models\\Page');
            })
            ->update(['faq_enabled' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasColumn('pages', 'faq_enabled')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            $table->dropIndex(['faq_enabled']);
            $table->dropColumn('faq_enabled');
        });
    }
};
