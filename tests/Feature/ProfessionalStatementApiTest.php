<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\PerformanceRecordService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfessionalStatementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function it_generates_statement_payload_and_exports(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->givePermissionTo(AdminPermission::MANAGE_DOCTORS->value);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Bottaro Giuseppe',
            'first_name' => 'Giuseppe',
            'last_name' => 'Bottaro',
            'area_name' => 'Cardiologia',
            'iban' => 'IT60X0542811101000000123456',
        ]);
        $category = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'sort_order' => 1],
        );
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'canonical_name' => 'Visita cardiologica',
            'slug' => 'cardiologia-visita-cardiologica',
        ]);
        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'price_amount' => 100,
            'is_active' => true,
        ]);

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-05-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(2),
            'quantity' => 2,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'pagata',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
        ], $user);

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-05-12',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
        ], $user);

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-05-14',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(3),
            'quantity' => 3,
            'unit_amount' => 120,
            'payment_method' => 'cash',
            'payment_status' => 'pagata',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_invoiced' => true,
        ], $user);

        $this->getJson("/api/v1/professional-statements/{$professional->id}?start_date=2026-05-01&end_date=2026-05-31")
            ->assertOk()
            ->assertJsonPath('professional.full_name', 'Bottaro Giuseppe')
            ->assertJsonPath('professional.iban', 'IT60X0542811101000000123456')
            ->assertJsonCount(2, 'records')
            ->assertJsonPath('totals.performance_count', 3)
            ->assertJsonPath('totals.already_liquidated_count', 2)
            ->assertJsonPath('totals.professional_amount', 210)
            ->assertJsonPath('totals.already_liquidated_amount', 140)
            ->assertJsonPath('records.0.is_invoiced', false)
            ->assertJsonPath('records.0.payment_status_label', 'Liquidata')
            ->assertJsonPath('records.0.notes', 'Gia liquidata')
            ->assertJsonPath('records.1.payment_status_label', 'Da liquidare')
            ->assertJsonPath('records.1.invoicing_status', 'Da fatturare')
            ->assertJsonMissingPath('totals.center_amount')
            ->assertJsonPath('message', 'Totale quote professionista da fatturare: € 210,00');

        $this->get("/api/v1/professional-statements/{$professional->id}/pdf?start_date=2026-05-01&end_date=2026-05-31")
            ->assertOk();

        $this->get("/api/v1/professional-statements/{$professional->id}/xlsx?start_date=2026-05-01&end_date=2026-05-31")
            ->assertOk();
    }

    private function createPatientIds(int $count): array
    {
        return Patient::factory()
            ->count($count)
            ->create()
            ->pluck('id')
            ->all();
    }
}
