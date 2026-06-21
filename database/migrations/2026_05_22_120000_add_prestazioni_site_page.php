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
        $slug = 'prestazioni';

        $payload = [
            'title' => 'Prestazioni',
            'template' => 'default',
            'excerpt' => 'Scopri le visite, gli esami diagnostici e i trattamenti disponibili presso Remedic.',
            'intro_text' => 'Scopri le visite, gli esami diagnostici e i trattamenti disponibili presso Remedic.',
            'seo_title' => 'Prestazioni | Remedic',
            'seo_description' => 'Esplora le prestazioni Remedic e orientati tra visite, esami diagnostici e trattamenti disponibili.',
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

        DB::table('pages')->where('slug', 'prestazioni')->delete();
    }
};
