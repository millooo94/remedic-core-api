<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProfessionalAvatarBackfill
{
    public function run(): int
    {
        $updated = 0;

        DB::table('professional_public_profiles')
            ->whereNotNull('profile_image_path')
            ->where('profile_image_path', '<>', '')
            ->orderBy('id')
            ->each(function (object $profile) use (&$updated): void {
                $updated += DB::table('professionals')
                    ->where('id', $profile->professional_id)
                    ->where(function ($query): void {
                        $query->whereNull('avatar_path')->orWhere('avatar_path', '');
                    })
                    ->update(['avatar_path' => $profile->profile_image_path]);
            });

        return $updated;
    }
}
