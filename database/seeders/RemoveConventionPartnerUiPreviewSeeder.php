<?php

namespace Database\Seeders;

use App\Models\ConventionPartner;
use Illuminate\Database\Seeder;

class RemoveConventionPartnerUiPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('La rimozione delle fixture UI Convenzioni è consentita solo in ambiente local.');
        }

        ConventionPartner::query()->where('name', 'like', '[DEMO] %')->delete();
    }
}
