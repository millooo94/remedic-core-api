<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MASTER_OWNER = 'App\\Models\\Service';

    private const PROFILE_OWNER = 'App\\Models\\ServiceWebProfile';

    private const DEFINITIONS = [
        'hero' => 'Hero / Prestazione',
        'what_is' => 'Che cos’è la prestazione?',
        'when_to_request' => 'Quando richiederla?',
        'procedure' => 'Come si svolge la prestazione?',
        'preparation' => 'Norme di preparazione e consigli utili',
        'price' => 'Quanto costa',
        'faqs' => 'Domande frequenti',
        'equipe' => 'Équipe per la prestazione',
    ];

    private const ALIASES = [
        'description' => 'what_is',
        'overview' => 'what_is',
        'intro' => 'procedure',
        'how_it_works' => 'procedure',
        'when' => 'when_to_request',
        'faq' => 'faqs',
        'doctors' => 'equipe',
    ];

    public function up(): void
    {
        DB::table('service_web_profiles')->orderBy('id')->each(function (object $profile): void {
            $legacySections = DB::table('sections')
                ->where('sectionable_type', self::MASTER_OWNER)
                ->where('sectionable_id', $profile->service_id)
                ->orderBy('sort_order')->orderBy('id')->get();

            foreach ($legacySections as $legacy) {
                $candidate = self::ALIASES[$legacy->key] ?? $legacy->key;
                $conflict = DB::table('sections')->where([
                    'sectionable_type' => self::PROFILE_OWNER,
                    'sectionable_id' => $profile->id,
                    'key' => $candidate,
                ])->exists();

                DB::table('sections')->where('id', $legacy->id)->update([
                    'sectionable_type' => self::PROFILE_OWNER,
                    'sectionable_id' => $profile->id,
                    'key' => $conflict ? 'legacy_'.$legacy->key.'_'.$legacy->id : $candidate,
                    'updated_at' => now(),
                ]);
            }

            DB::table('faq_items')
                ->where('faqable_type', self::MASTER_OWNER)
                ->where('faqable_id', $profile->service_id)
                ->update([
                    'faqable_type' => self::PROFILE_OWNER,
                    'faqable_id' => $profile->id,
                    'updated_at' => now(),
                ]);

            $master = DB::table('services')->where('id', $profile->service_id)->first();
            foreach (array_keys(self::DEFINITIONS) as $order => $key) {
                if (DB::table('sections')->where([
                    'sectionable_type' => self::PROFILE_OWNER,
                    'sectionable_id' => $profile->id,
                    'key' => $key,
                ])->exists()) {
                    continue;
                }

                $content = match ($key) {
                    'what_is' => $master->description ?? null,
                    'procedure' => $master->intro_text ?? null,
                    'preparation' => $master->preparation_notes ?? null,
                    default => null,
                };

                DB::table('sections')->insert([
                    'sectionable_type' => self::PROFILE_OWNER,
                    'sectionable_id' => $profile->id,
                    'key' => $key,
                    'title' => self::DEFINITIONS[$key],
                    'content' => $content,
                    'sort_order' => $order,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Reparenting is deliberately irreversible to avoid overwriting later Web edits.
    }
};
