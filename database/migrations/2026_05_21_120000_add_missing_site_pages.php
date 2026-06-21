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
            'medicina-di-genere' => [
                'title' => 'Medicina di genere',
                'template' => 'default',
                'excerpt' => 'Percorsi di prevenzione e cura pensati per rispondere alle esigenze specifiche di donne e uomini nelle diverse fasi della vita.',
                'intro_text' => 'Percorsi di prevenzione e cura pensati per rispondere alle esigenze specifiche di donne e uomini nelle diverse fasi della vita.',
                'seo_title' => 'Medicina di genere | Remedic',
                'seo_description' => 'Scopri i percorsi Remedic dedicati alla medicina di genere, con un approccio piu personalizzato alla prevenzione e alla cura.',
            ],
            'check-up' => [
                'title' => 'Check-up',
                'template' => 'default',
                'excerpt' => 'Percorsi di controllo e prevenzione pensati per monitorare la salute e aiutarti a scegliere il check-up piu adatto.',
                'intro_text' => 'Percorsi di controllo pensati per monitorare la tua salute e orientarti nella prevenzione con un approccio piu chiaro e coordinato.',
                'seo_title' => 'Check-up | Remedic',
                'seo_description' => 'Scopri i check-up Remedic e i percorsi di prevenzione pensati per eta, fattori di rischio e obiettivi di salute.',
            ],
            'equipe' => [
                'title' => 'Equipe',
                'template' => 'default',
                'excerpt' => 'Medici e specialisti selezionati per accompagnarti con competenza, ascolto e attenzione.',
                'intro_text' => 'Trova il professionista piu adatto alla tua visita o al tuo percorso di cura.',
                'seo_title' => 'Equipe Remedic | Medici e Specialisti',
                'seo_description' => 'Scopri l\'equipe Remedic: medici e specialisti selezionati per accompagnarti con competenza, ascolto e attenzione.',
            ],
            'specializzazioni' => [
                'title' => 'Specializzazioni',
                'template' => 'default',
                'excerpt' => 'Trova l\'area medica piu adatta alle tue esigenze e scopri visite, prestazioni e professionisti disponibili presso Remedic.',
                'intro_text' => 'Trova l\'area medica piu adatta alle tue esigenze e scopri visite, prestazioni e professionisti disponibili presso Remedic.',
                'seo_title' => 'Specializzazioni | Remedic',
                'seo_description' => 'Esplora le specializzazioni Remedic e orientati tra aree mediche, professionisti e prestazioni disponibili.',
            ],
        ];

        foreach ($pages as $slug => $page) {
            $exists = DB::table('pages')->where('slug', $slug)->exists();

            $payload = [
                'title' => $page['title'],
                'template' => $page['template'],
                'excerpt' => $page['excerpt'],
                'intro_text' => $page['intro_text'],
                'seo_title' => $page['seo_title'],
                'seo_description' => $page['seo_description'],
                'is_active' => true,
                'published_at' => $now,
                'updated_at' => $now,
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

        DB::table('pages')
            ->whereIn('slug', ['medicina-di-genere', 'check-up', 'equipe', 'specializzazioni'])
            ->delete();
    }
};
