<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EQUIPE_OWNER = 'App\\Models\\ProfessionalPublicProfile';

    public function up(): void
    {
        DB::table('professional_specialization')
            ->select('professional_id')
            ->distinct()
            ->orderBy('professional_id')
            ->pluck('professional_id')
            ->each(function (int $professionalId): void {
                $links = DB::table('professional_specialization')
                    ->where('professional_id', $professionalId)
                    ->orderBy('sort_order')->orderBy('specialization_id')->get();
                $primary = $links->first();

                DB::table('professional_specialization')
                    ->where('professional_id', $professionalId)
                    ->update(['is_primary' => false]);
                DB::table('professional_specialization')
                    ->where('id', $primary->id)
                    ->update(['is_primary' => true]);
            });

        DB::table('professional_public_profiles')->orderBy('id')->each(function (object $profile): void {
            $exists = DB::table('sections')->where([
                'sectionable_type' => self::EQUIPE_OWNER,
                'sectionable_id' => $profile->id,
                'key' => 'services',
            ])->exists();
            if ($exists) {
                return;
            }

            $lastOrder = (int) DB::table('sections')
                ->where('sectionable_type', self::EQUIPE_OWNER)
                ->where('sectionable_id', $profile->id)
                ->max('sort_order');
            DB::table('sections')->insert([
                'sectionable_type' => self::EQUIPE_OWNER,
                'sectionable_id' => $profile->id,
                'key' => 'services',
                'title' => 'Prestazioni',
                'sort_order' => $lastOrder + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Primary normalization and editorial sections are intentional repairs.
        // A rollback must not remove content subsequently edited by users.
    }
};
