<?php

namespace Database\Seeders;

use App\Models\CountingPeriod;
use Illuminate\Database\Seeder;

class CountingPeriodSeeder extends Seeder
{
    public function run(): void
    {
        $periods = [
            ['label' => 'Seconda metà febbraio - maggio 2026', 'start_date' => '2026-02-15', 'end_date' => '2026-05-31', 'is_closed' => false],
            ['label' => 'Giugno - agosto 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-08-31', 'is_closed' => false],
        ];

        foreach ($periods as $period) {
            CountingPeriod::query()->updateOrCreate(
                ['label' => $period['label']],
                $period,
            );
        }
    }
}
