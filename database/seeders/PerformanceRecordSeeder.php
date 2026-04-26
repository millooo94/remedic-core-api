<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\PerformanceRecord;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\User;
use App\Services\PerformanceRecordService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class PerformanceRecordSeeder extends Seeder
{
    public function run(): void
    {
        $primaryAdminEmail = mb_strtolower(trim((string) config('auth.primary_admin.email')));
        $user = User::query()
            ->when($primaryAdminEmail !== '', fn ($query) => $query->where('email', $primaryAdminEmail))
            ->first()
            ?? User::query()->where('role', UserRole::Admin)->first();

        if (! $user) {
            throw new RuntimeException('Nessun utente admin disponibile per il seeding dei record prestazionali. Esegui prima AdminUserSeeder.');
        }

        $service = app(PerformanceRecordService::class);
        $professionals = Professional::query()
            ->get()
            ->keyBy(fn (Professional $professional) => $this->normalizePersonKey($professional->full_name));

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
            ['professional' => 'Zappala Simona', 'service' => 'Biorivitalizzazione viso', 'date' => '2026-07-09', 'unit_amount' => 120, 'mode' => 'percentage', 'percentage_value' => 70, 'payment_method' => 'card'],
            ['professional' => 'Patane Giorgia', 'service' => 'Supporto tecnico esame', 'date' => '2026-08-03', 'unit_amount' => 70, 'mode' => 'fixed', 'fixed_amount' => 30, 'payment_method' => 'cash'],
        ];

        foreach ($records as $record) {
            $professionalName = (string) $record['professional'];
            $professional = $professionals->get($this->normalizePersonKey($professionalName));

            if (! $professional) {
                throw new RuntimeException("Professionista non trovato nel seeding prestazioni: {$professionalName}");
            }

            $link = ProfessionalService::query()
                ->where('professional_id', $professional->id)
                ->whereHas('service', fn ($query) => $query->where('display_name', $record['service']))
                ->with('service')
                ->first();

            $payload = [
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
            ];

            $existingRecord = PerformanceRecord::query()
                ->where('performed_at', $record['date'])
                ->where('professional_id', $professional->id)
                ->where('service_name_snapshot', $record['service'])
                ->first();

            if ($existingRecord) {
                $service->update($existingRecord, $payload, $user);
                continue;
            }

            $service->create($payload, $user);
        }
    }

    private function normalizePersonKey(string $fullName): string
    {
        return Str::of($fullName)
            ->replace(["\u{2019}", "\u{2018}", '`'], "'")
            ->ascii()
            ->lower()
            ->squish()
            ->value();
    }
}
