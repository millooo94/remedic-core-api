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
        $slug = 'contatti';

        $payload = [
            'title' => 'Contatti',
            'template' => 'contact',
            'excerpt' => 'Contatta Remedic ad Acireale per prenotare visite, diagnostica e percorsi di prevenzione o ricevere supporto nella scelta del percorso piu adatto.',
            'intro_text' => 'Siamo ad Acireale e ti aiutiamo a prenotare visite, diagnostica e percorsi di prevenzione con un orientamento chiaro e umano.',
            'seo_title' => 'Contatti | Remedic',
            'seo_description' => 'Contatta Remedic ad Acireale per prenotare visite, diagnostica e percorsi di prevenzione o ricevere supporto nella scelta del percorso piu adatto.',
            'faq_enabled' => true,
            'is_active' => true,
            'published_at' => $now,
            'updated_at' => $now,
        ];

        $exists = DB::table('pages')->where('slug', $slug)->exists();

        if ($exists) {
            DB::table('pages')->where('slug', $slug)->update($payload);
            return;
        }

        DB::table('pages')->insert([
            'slug' => $slug,
            ...$payload,
            'created_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        DB::table('pages')->where('slug', 'contatti')->delete();
    }
};
