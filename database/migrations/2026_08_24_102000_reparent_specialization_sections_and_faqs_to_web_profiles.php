<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MASTER_OWNER = 'App\\Models\\Specialization';

    private const PROFILE_OWNER = 'App\\Models\\SpecializationWebProfile';

    private const DEFINITIONS = [
        'hero' => 'Hero / Area medica',
        'scope' => 'Di cosa si occupa',
        'when_useful' => 'Quando è utile una visita',
        'visit_process' => 'Cosa succede durante la visita',
        'services' => 'Prestazioni',
        'faqs' => 'Domande frequenti',
        'equipe' => 'Équipe',
    ];

    private const ALIASES = [
        'what_is' => 'scope', 'whatis' => 'scope', 'overview' => 'scope', 'cosa_fa' => 'scope',
        'when' => 'when_useful', 'when_to_book' => 'when_useful', 'quando_prenotare' => 'when_useful',
    ];

    public function up(): void
    {
        DB::table('specialization_web_profiles')->orderBy('id')->each(function (object $profile): void {
            $legacySections = DB::table('sections')
                ->where('sectionable_type', self::MASTER_OWNER)
                ->where('sectionable_id', $profile->specialization_id)
                ->orderBy('sort_order')->orderBy('id')->get();

            foreach ($legacySections as $legacy) {
                $candidate = self::ALIASES[$legacy->key] ?? $legacy->key;
                $conflict = DB::table('sections')
                    ->where('sectionable_type', self::PROFILE_OWNER)
                    ->where('sectionable_id', $profile->id)
                    ->where('key', $candidate)
                    ->exists();

                DB::table('sections')->where('id', $legacy->id)->update([
                    'sectionable_type' => self::PROFILE_OWNER,
                    'sectionable_id' => $profile->id,
                    'key' => $conflict ? 'legacy_'.$legacy->key.'_'.$legacy->id : $candidate,
                    'updated_at' => now(),
                ]);
            }

            DB::table('faq_items')
                ->where('faqable_type', self::MASTER_OWNER)
                ->where('faqable_id', $profile->specialization_id)
                ->update([
                    'faqable_type' => self::PROFILE_OWNER,
                    'faqable_id' => $profile->id,
                    'updated_at' => now(),
                ]);

            $master = DB::table('specializations')->where('id', $profile->specialization_id)->first();
            foreach (array_keys(self::DEFINITIONS) as $order => $key) {
                if (DB::table('sections')->where([
                    'sectionable_type' => self::PROFILE_OWNER,
                    'sectionable_id' => $profile->id,
                    'key' => $key,
                ])->exists()) {
                    continue;
                }

                $content = $key === 'scope' ? ($master->intro_text ?? null)
                    : ($key === 'when_useful' ? ($master->local_intro_text ?? null) : null);
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
        // Reparenting is deliberately not reversed: doing so could overwrite Web edits.
    }
};
