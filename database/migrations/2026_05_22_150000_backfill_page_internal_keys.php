<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasColumn('pages', 'internal_key')) {
            return;
        }

        $titleToInternalKey = [
            'Chi siamo' => 'chi-siamo',
            'Privacy Policy' => 'privacy',
            'Cookie Policy' => 'cookie-policy',
            'Contatti' => 'contatti',
            'Check-up' => 'check-up',
            'Equipe' => 'equipe',
            'Medicina di genere' => 'medicina-di-genere',
            'Specializzazioni' => 'specializzazioni',
            'Prestazioni' => 'prestazioni',
            'Check-up donna' => 'check-up-donna',
            'Check-up uomo' => 'check-up-uomo',
            'Check-up personalizzato' => 'check-up-personalizzato',
            'Check-up cardiologico' => 'check-up-cardiologico',
            'Check-up dermatologico' => 'check-up-dermatologico',
            'Check-up ginecologico' => 'check-up-ginecologico',
            'Check-up urologico' => 'check-up-urologico',
            'Check-up endocrinologico' => 'check-up-endocrinologico',
            'Salute della donna' => 'medicina-di-genere-donna',
            'Salute dell\'uomo' => 'medicina-di-genere-uomo',
            'Prevenzione per eta' => 'medicina-di-genere-prevenzione-per-eta',
        ];

        foreach ($titleToInternalKey as $title => $internalKey) {
            DB::table('pages')
                ->where('title', $title)
                ->update([
                    'internal_key' => $internalKey,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasColumn('pages', 'internal_key')) {
            return;
        }

        DB::table('pages')
            ->whereNotNull('slug')
            ->update([
                'internal_key' => DB::raw('slug'),
            ]);
    }
};
