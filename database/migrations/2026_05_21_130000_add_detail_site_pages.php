<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        $now = now();

        $pages = [
            'check-up-donna' => [
                'title' => 'Check-up donna',
                'excerpt' => 'Percorso completo di prevenzione pensato per la salute della donna.',
                'seo_title' => 'Check-up donna | Remedic',
                'seo_description' => 'Scopri il check-up donna di Remedic e il percorso di prevenzione costruito per la salute femminile.',
            ],
            'check-up-uomo' => [
                'title' => 'Check-up uomo',
                'excerpt' => 'Percorso di prevenzione per la salute dell\'uomo a tutte le eta.',
                'seo_title' => 'Check-up uomo | Remedic',
                'seo_description' => 'Scopri il check-up uomo di Remedic e i controlli di prevenzione dedicati alla salute maschile.',
            ],
            'check-up-personalizzato' => [
                'title' => 'Check-up personalizzato',
                'excerpt' => 'Un percorso di prevenzione costruito su misura per le tue esigenze.',
                'seo_title' => 'Check-up personalizzato | Remedic',
                'seo_description' => 'Scopri il check-up personalizzato di Remedic e costruisci un percorso di prevenzione su misura.',
            ],
            'check-up-cardiologico' => [
                'title' => 'Check-up cardiologico',
                'excerpt' => 'Percorso di prevenzione per la salute del cuore.',
                'seo_title' => 'Check-up cardiologico | Remedic',
                'seo_description' => 'Scopri il check-up cardiologico di Remedic e i controlli dedicati alla prevenzione cardiovascolare.',
            ],
            'check-up-dermatologico' => [
                'title' => 'Check-up dermatologico',
                'excerpt' => 'Percorso di prevenzione per la salute della pelle.',
                'seo_title' => 'Check-up dermatologico | Remedic',
                'seo_description' => 'Scopri il check-up dermatologico di Remedic e i controlli dedicati alla salute della pelle.',
            ],
            'check-up-ginecologico' => [
                'title' => 'Check-up ginecologico',
                'excerpt' => 'Percorso di prevenzione per la salute ginecologica.',
                'seo_title' => 'Check-up ginecologico | Remedic',
                'seo_description' => 'Scopri il check-up ginecologico di Remedic e i controlli dedicati alla salute ginecologica.',
            ],
            'check-up-urologico' => [
                'title' => 'Check-up urologico',
                'excerpt' => 'Percorso di prevenzione per la salute urologica.',
                'seo_title' => 'Check-up urologico | Remedic',
                'seo_description' => 'Scopri il check-up urologico di Remedic e i controlli dedicati alla prevenzione urologica.',
            ],
            'check-up-endocrinologico' => [
                'title' => 'Check-up endocrinologico',
                'excerpt' => 'Percorso di prevenzione per la salute metabolica e ormonale.',
                'seo_title' => 'Check-up endocrinologico | Remedic',
                'seo_description' => 'Scopri il check-up endocrinologico di Remedic e i controlli dedicati a metabolismo, tiroide e ormoni.',
            ],
            'medicina-di-genere-donna' => [
                'title' => 'Salute della donna',
                'excerpt' => 'Percorso di medicina di genere dedicato alla salute femminile nelle diverse fasi della vita.',
                'seo_title' => 'Salute della donna | Medicina di genere | Remedic',
                'seo_description' => 'Scopri il percorso Remedic dedicato alla salute della donna nell\'ambito della medicina di genere.',
            ],
            'medicina-di-genere-uomo' => [
                'title' => 'Salute dell\'uomo',
                'excerpt' => 'Percorso di medicina di genere dedicato alla salute maschile con attenzione a prevenzione e monitoraggio.',
                'seo_title' => 'Salute dell\'uomo | Medicina di genere | Remedic',
                'seo_description' => 'Scopri il percorso Remedic dedicato alla salute dell\'uomo nell\'ambito della medicina di genere.',
            ],
            'medicina-di-genere-prevenzione-per-eta' => [
                'title' => 'Prevenzione per eta',
                'excerpt' => 'Percorso di medicina di genere per orientare i controlli in base alle diverse fasi della vita.',
                'seo_title' => 'Prevenzione per eta | Medicina di genere | Remedic',
                'seo_description' => 'Scopri il percorso Remedic di prevenzione per eta nell\'ambito della medicina di genere.',
            ],
        ];

        foreach ($pages as $slug => $page) {
            $exists = DB::table('pages')->where('slug', $slug)->exists();

            $payload = [
                'title' => $page['title'],
                'template' => 'default',
                'excerpt' => $page['excerpt'],
                'intro_text' => $page['excerpt'],
                'seo_title' => $page['seo_title'],
                'seo_description' => $page['seo_description'],
                'is_active' => true,
                'published_at' => $now,
                'updated_at' => $now,
                'faq_enabled' => true,
            ];

            if ($exists) {
                DB::table('pages')->where('slug', $slug)->update($payload);
                continue;
            }

            DB::table('pages')->insert([
                'slug' => $slug,
                ...$payload,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        DB::table('pages')->whereIn('slug', [
            'check-up-donna',
            'check-up-uomo',
            'check-up-personalizzato',
            'check-up-cardiologico',
            'check-up-dermatologico',
            'check-up-ginecologico',
            'check-up-urologico',
            'check-up-endocrinologico',
            'medicina-di-genere-donna',
            'medicina-di-genere-uomo',
            'medicina-di-genere-prevenzione-per-eta',
        ])->delete();
    }
};
