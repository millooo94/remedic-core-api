<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CountingPeriod;
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

class CountingPeriodApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_quarterly_summary_by_professional(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Bottaro Giuseppe',
            'area_name' => 'Cardiologia',
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

        $period = CountingPeriod::query()->create([
            'label' => 'Febbraio - Aprile 2026',
            'start_date' => '2026-02-15',
            'end_date' => '2026-04-30',
            'is_closed' => false,
        ]);

        $serviceLayer = app(PerformanceRecordService::class);
        $serviceLayer->create([
            'performed_at' => '2026-03-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
        ], $user);
        $serviceLayer->create([
            'performed_at' => '2026-04-11',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'quantity' => 3,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'calculation_mode' => 'fixed',
            'fixed_amount' => 30,
        ], $user);

        $this->getJson("/api/v1/counting-periods/{$period->id}/summary")
            ->assertOk()
            ->assertJsonPath('rows.0.performance_count', 5)
            ->assertJsonPath('rows.0.professional_total', 170)
            ->assertJsonPath('rows.0.center_total', 330)
            ->assertJsonPath('rows.0.message', 'Totale da fatturare a Humancare Telemedicine S.r.l.: € 170,00');
    }
}

