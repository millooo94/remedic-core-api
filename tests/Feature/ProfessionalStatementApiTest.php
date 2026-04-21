<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\PerformanceRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfessionalStatementApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_statement_payload_and_exports(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Bottaro Giuseppe',
            'first_name' => 'Giuseppe',
            'last_name' => 'Bottaro',
            'area_name' => 'Cardiologia',
            'iban' => 'IT60X0542811101000000123456',
        ]);
        $category = ServiceCategory::factory()->create(['name' => 'Cardiologia', 'slug' => 'cardiologia']);
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
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_invoiced' => true,
        ], $user);

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-05-14',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_amount' => 120,
            'payment_method' => 'cash',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
        ], $user);

        $this->getJson("/api/v1/professional-statements/{$professional->id}?start_date=2026-05-01&end_date=2026-05-31")
            ->assertOk()
            ->assertJsonPath('professional.full_name', 'Bottaro Giuseppe')
            ->assertJsonPath('professional.iban', 'IT60X0542811101000000123456')
            ->assertJsonPath('totals.performance_count', 1)
            ->assertJsonPath('totals.already_invoiced_count', 1)
            ->assertJsonPath('totals.professional_amount', 84)
            ->assertJsonPath('totals.already_invoiced_amount', 70)
            ->assertJsonPath('records.0.is_invoiced', true)
            ->assertJsonPath('records.0.invoicing_status', 'Gia fatturata')
            ->assertJsonMissingPath('totals.center_amount')
            ->assertJsonPath('message', 'Totale da fatturare a Humancare Telemedicine S.r.l.: € 84,00');

        $this->get("/api/v1/professional-statements/{$professional->id}/pdf?start_date=2026-05-01&end_date=2026-05-31")
            ->assertOk();

        $this->get("/api/v1/professional-statements/{$professional->id}/xlsx?start_date=2026-05-01&end_date=2026-05-31")
            ->assertOk();
    }
}
