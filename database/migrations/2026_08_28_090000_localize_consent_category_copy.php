<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->string('label')->nullable()->after('title');
            $table->text('description')->nullable()->after('label');
        });

        if (! DB::table('consent_categories')->where('key', 'statistics')->exists()) {
            DB::table('consent_categories')->where('key', 'analytics')->update(['key' => 'statistics']);
        }

        collect([
            ['key' => 'necessary', 'name' => 'Necessari', 'description' => 'Indispensabili per il funzionamento del sito e la sicurezza. Sempre attivi.', 'default_state' => true, 'is_required' => true, 'is_active' => true, 'sort_order' => 0],
            ['key' => 'preferences', 'name' => 'Preferenze', 'description' => 'Ricordano le scelte non indispensabili e personalizzano l’esperienza.', 'default_state' => false, 'is_required' => false, 'is_active' => true, 'sort_order' => 1],
            ['key' => 'statistics', 'name' => 'Statistiche', 'description' => 'Aiutano a comprendere e migliorare l’esperienza in forma aggregata.', 'default_state' => false, 'is_required' => false, 'is_active' => true, 'sort_order' => 2],
            ['key' => 'marketing', 'name' => 'Marketing', 'description' => 'Abilitano comunicazioni e contenuti promozionali basati sulle preferenze.', 'default_state' => false, 'is_required' => false, 'is_active' => true, 'sort_order' => 3],
        ])->each(function (array $category): void {
            DB::table('consent_categories')->insertOrIgnore([...$category, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->dropColumn(['label', 'description']);
        });
    }
};
