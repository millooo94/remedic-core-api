<?php

namespace Database\Seeders;

use App\Models\ConventionPartner;
use Illuminate\Database\Seeder;

class ConventionPartnerUiPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Le fixture UI Convenzioni sono consentite solo in ambiente local.');
        }

        foreach ([
            ['[DEMO] Salute Network', 'network', true],
            ['[DEMO] Protezione Medica', 'insurance', true],
            ['[DEMO] Fondo Sanitario Uno', 'fund', true],
            ['[DEMO] Assistenza Plus', 'insurance', false],
            ['[DEMO] Network Aziendale', 'network', true],
            ['[DEMO] Welfare Salute', 'entity', false],
        ] as $order => [$name, $type, $active]) {
            ConventionPartner::query()->updateOrCreate(['name' => $name], ['type' => $type, 'logo_path' => null, 'is_active' => $active, 'sort_order' => 10000 + $order]);
        }
    }
}
