<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
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

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_monthly_economic_cards_and_no_undefined_labels(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Russo Ilenia',
            'area_name' => 'Nutrizione',
        ]);
        $secondProfessional = Professional::factory()->create([
            'full_name' => 'Bianchi Luca',
            'area_name' => 'Nutrizione',
        ]);
        $category = ServiceCategory::factory()->create(['name' => 'Nutrizione', 'slug' => 'nutrizione']);
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Controllo nutrizionale',
            'canonical_name' => 'Controllo nutrizionale',
            'slug' => 'nutrizione-controllo-nutrizionale',
        ]);
        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'price_amount' => 100,
            'is_active' => true,
        ]);
        ProfessionalService::query()->create([
            'professional_id' => $secondProfessional->id,
            'service_id' => $service->id,
            'price_amount' => 200,
            'is_active' => true,
        ]);

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => true,
        ], $user);
        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-12',
            'professional_id' => $secondProfessional->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_amount' => 200,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 60,
            'is_black' => false,
        ], $user);
        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-15',
            'professional_id' => $secondProfessional->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_amount' => 150,
            'payment_method' => 'cash',
            'calculation_mode' => 'percentage',
            'percentage_value' => 50,
            'is_black' => false,
        ], $user);

        $expenseCategory = ExpenseCategory::factory()->create([
            'name' => 'Affitto',
            'slug' => 'affitto',
            'type' => 'fixed',
        ]);
        ExpenseRecord::query()->create([
            'expense_category_id' => $expenseCategory->id,
            'expense_date' => '2026-03-05',
            'competence_month' => 3,
            'competence_year' => 2026,
            'description' => 'Affitto marzo',
            'type' => 'fixed',
            'amount' => 20,
            'payment_status' => 'pagata',
        ]);

        $this->getJson('/api/v1/dashboard/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_performances', 3)
            ->assertJsonPath('cards.total_center_amount', 185)
            ->assertJsonPath('cards.total_professional_amount', 265)
            ->assertJsonPath('cards.revenue_payment_breakdown.cash', 250)
            ->assertJsonPath('cards.revenue_payment_breakdown.card', 200)
            ->assertJsonPath('cards.average_performance_cost', 150)
            ->assertJsonPath('cards.average_performance_cost_excluding_black', 175)
            ->assertJsonPath('cards.total_fixed_costs', 20)
            ->assertJsonPath('cards.black', 30)
            ->assertJsonPath('cards.net_center_margin', 165)
            ->assertJsonPath('cards.top_by_performance_count.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.top_by_performance_count.performances', 2)
            ->assertJsonPath('cards.top_by_revenue.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.top_by_revenue.revenue_total', 350)
            ->assertJsonPath('cards.performance_count_ranking.0.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.performance_count_ranking.1.professional_name', 'Russo Ilenia')
            ->assertJsonPath('cards.revenue_ranking.0.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.revenue_ranking.1.professional_name', 'Russo Ilenia');

        $response = $this->getJson('/api/v1/dashboard/monthly-trends?year=2026')
            ->assertOk();

        $payload = $response->json();
        $this->assertNotContains('undefined', array_column($payload['monthly_trends'], 'label'));
        $this->assertNotContains('undefined', array_column($payload['professional_split'], 'label'));
        $this->assertNotContains('undefined', array_column($payload['expense_category_split'], 'label'));
    }
}
