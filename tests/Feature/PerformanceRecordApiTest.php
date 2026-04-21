<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerformanceRecordApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_performance_record_with_snapshots_and_totals(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

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

        $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => true,
        ])->assertCreated()
            ->assertJsonPath('professional_name_snapshot', 'Bottaro Giuseppe')
            ->assertJsonPath('category_name_snapshot', 'Cardiologia')
            ->assertJsonPath('service_name_snapshot', 'Visita cardiologica')
            ->assertJsonPath('total_amount', '100.00')
            ->assertJsonPath('professional_amount', '70.00')
            ->assertJsonPath('center_amount', '30.00')
            ->assertJsonPath('payment_method', 'card')
            ->assertJsonPath('is_black', true);
    }

    #[Test]
    public function it_rejects_fixed_amount_higher_than_total_amount(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create();

        $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-10',
            'professional_id' => $professional->id,
            'service_name' => 'Prestazione manuale',
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'calculation_mode' => 'fixed',
            'fixed_amount' => 120,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['fixed_amount']);
    }
}
