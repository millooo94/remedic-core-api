<?php

namespace Database\Seeders;

use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Services\PerformanceRecordService;
use Illuminate\Database\Seeder;

class PerformanceRecordSeeder extends Seeder
{
    public function run(): void
    {
        $user = \App\Models\User::query()->where('email', 'admin@example.com')->firstOrFail();
        $service = app(PerformanceRecordService::class);

        $records = [
            ['professional' => 'Arena Sebastiano', 'service' => 'Visita neurologica', 'date' => '2026-02-18', 'unit_amount' => 120, 'mode' => 'percentage', 'percentage_value' => 70, 'payment_method' => 'card'],
            ['professional' => 'Bottaro Giuseppe', 'service' => 'Visita cardiologica + ECG', 'date' => '2026-03-07', 'unit_amount' => 140, 'mode' => 'percentage', 'percentage_value' => 60, 'payment_method' => 'cash'],
            ['professional' => 'Cantone Saveria Maria', 'service' => 'Visita ginecologica + Pap test', 'date' => '2026-03-14', 'unit_amount' => 150, 'mode' => 'percentage', 'percentage_value' => 50, 'payment_method' => 'card'],
            ['professional' => 'Di Salvo Antonino', 'service' => 'Mappatura nevi', 'date' => '2026-03-21', 'unit_amount' => 115, 'mode' => 'percentage', 'percentage_value' => 33, 'payment_method' => 'cash'],
            ['professional' => 'Maugeri Claudia', 'service' => 'Filler labbra', 'date' => '2026-04-04', 'unit_amount' => 180, 'mode' => 'fixed', 'fixed_amount' => 80, 'payment_method' => 'card'],
            ['professional' => 'Scuderi Rosario', 'service' => 'Ecocolordoppler arti inferiori', 'date' => '2026-04-18', 'unit_amount' => 130, 'mode' => 'percentage', 'percentage_value' => 60, 'payment_method' => 'card'],
            ['professional' => 'Di Dio Agata', 'service' => 'Visita urologica generale', 'date' => '2026-05-06', 'unit_amount' => 120, 'mode' => 'percentage', 'percentage_value' => 70, 'payment_method' => 'cash'],
            ['professional' => 'Liguori Livia', 'service' => 'Visita dermatologica', 'date' => '2026-05-23', 'unit_amount' => 100, 'mode' => 'fixed', 'fixed_amount' => 40, 'payment_method' => 'card'],
            ['professional' => 'Rapisarda Giovanni Mario', 'service' => 'Visita internistica', 'date' => '2026-06-10', 'unit_amount' => 125, 'mode' => 'percentage', 'percentage_value' => 60, 'payment_method' => 'cash'],
            ['professional' => 'Russo Ilenia', 'service' => 'Controllo nutrizionale', 'date' => '2026-06-21', 'unit_amount' => 60, 'mode' => 'percentage', 'percentage_value' => 50, 'payment_method' => 'card'],
            ['professional' => 'ZappalÃ  Simona', 'service' => 'Biorivitalizzazione viso', 'date' => '2026-07-09', 'unit_amount' => 120, 'mode' => 'percentage', 'percentage_value' => 70, 'payment_method' => 'card'],
            ['professional' => 'PatanÃ¨ Giorgia', 'service' => 'Supporto tecnico esame', 'date' => '2026-08-03', 'unit_amount' => 70, 'mode' => 'fixed', 'fixed_amount' => 30, 'payment_method' => 'cash'],
        ];

        foreach ($records as $record) {
            $professional = Professional::query()->where('full_name', $record['professional'])->firstOrFail();
            $link = ProfessionalService::query()
                ->where('professional_id', $professional->id)
                ->whereHas('service', fn ($query) => $query->where('display_name', $record['service']))
                ->with('service')
                ->first();

            $service->create([
                'performed_at' => $record['date'],
                'professional_id' => $professional->id,
                'service_id' => $link?->service_id,
                'service_name' => $record['service'],
                'quantity' => 1,
                'unit_amount' => $record['unit_amount'],
                'payment_method' => $record['payment_method'],
                'calculation_mode' => $record['mode'],
                'percentage_value' => $record['percentage_value'] ?? null,
                'fixed_amount' => $record['fixed_amount'] ?? null,
                'notes' => null,
            ], $user);
        }
    }
}
