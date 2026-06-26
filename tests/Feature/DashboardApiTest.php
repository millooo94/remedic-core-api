<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\PerformanceRecordService;
use Illuminate\Support\Facades\Artisan;
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
        $category = $this->findOrCreateCategory('Nutrizione', 'nutrizione');
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
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'payment_status' => 'pagata',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => true,
        ], $user);
        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-12',
            'professional_id' => $secondProfessional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(3),
            'quantity' => 3,
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
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 150,
            'payment_method' => 'cash',
            'payment_status' => 'pagata',
            'calculation_mode' => 'percentage',
            'percentage_value' => 50,
            'is_black' => false,
            'is_promo' => true,
        ], $user);

        $expenseCategory = ExpenseCategory::factory()->create([
            'name' => 'Affitto',
            'slug' => 'affitto',
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
            ->assertJsonPath('cards.total_performances', 5)
            ->assertJsonPath('cards.performance_type_breakdown.standard', 3)
            ->assertJsonPath('cards.performance_type_breakdown.black', 1)
            ->assertJsonPath('cards.performance_type_breakdown.promo', 1)
            ->assertJsonPath('cards.total_center_amount', 345)
            ->assertJsonPath('cards.total_professional_amount', 505)
            ->assertJsonPath('cards.revenue_payment_breakdown.cash', 250)
            ->assertJsonPath('cards.revenue_payment_breakdown.card', 600)
            ->assertJsonPath('cards.revenue_payment_breakdown.cash_breakdown.black', 100)
            ->assertJsonPath('cards.revenue_payment_breakdown.cash_breakdown.fatturati', 150)
            ->assertJsonPath('cards.average_performance_cost', 175)
            ->assertJsonPath('cards.average_performance_cost_excluding_black', 200)
            ->assertJsonPath('cards.average_center_gain_performance', 67.5)
            ->assertJsonPath('cards.average_center_gain_performance_excluding_black', 80)
            ->assertJsonPath('cards.total_fixed_costs', 20)
            ->assertJsonPath('cards.total_variable_costs', 505)
            ->assertJsonPath('cards.total_center_costs', 525)
            ->assertJsonPath('cards.black', 30)
            ->assertJsonPath('cards.net_center_margin', 325)
            ->assertJsonPath('cards.professional_amount_breakdown.total', 505)
            ->assertJsonPath('cards.professional_amount_breakdown.liquidated', 145)
            ->assertJsonPath('cards.professional_amount_breakdown.to_liquidate', 360)
            ->assertJsonPath('cards.professional_amount_breakdown.fiscal_split.total.white', 435)
            ->assertJsonPath('cards.professional_amount_breakdown.fiscal_split.total.black', 70)
            ->assertJsonPath('cards.professional_amount_breakdown.fiscal_split.liquidated.white', 75)
            ->assertJsonPath('cards.professional_amount_breakdown.fiscal_split.liquidated.black', 70)
            ->assertJsonPath('cards.professional_amount_breakdown.fiscal_split.to_liquidate.white', 360)
            ->assertJsonPath('cards.professional_amount_breakdown.fiscal_split.to_liquidate.black', 0)
            ->assertJsonPath('cards.top_by_performance_count.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.top_by_performance_count.performances', 4)
            ->assertJsonPath('cards.top_by_performance_count.promo_performances', 1)
            ->assertJsonPath('cards.top_by_revenue.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.top_by_revenue.revenue_total', 750)
            ->assertJsonPath('cards.top_by_specialization.label', 'Nutrizione')
            ->assertJsonPath('cards.top_by_specialization.performances', 5)
            ->assertJsonPath('cards.top_by_specialization.promo_performances', 1)
            ->assertJsonPath('cards.top_by_service.label', 'Controllo nutrizionale')
            ->assertJsonPath('cards.top_by_service.performances', 5)
            ->assertJsonPath('cards.top_by_service.promo_performances', 1)
            ->assertJsonPath('cards.performance_count_ranking.0.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.performance_count_ranking.0.promo_performances', 1)
            ->assertJsonPath('cards.performance_count_ranking.1.professional_name', 'Russo Ilenia')
            ->assertJsonPath('cards.revenue_ranking.0.professional_name', 'Bianchi Luca')
            ->assertJsonPath('cards.revenue_ranking.1.professional_name', 'Russo Ilenia')
            ->assertJsonPath('cards.specialization_ranking.0.label', 'Nutrizione')
            ->assertJsonPath('cards.specialization_ranking.0.promo_performances', 1)
            ->assertJsonPath('cards.service_ranking.0.promo_performances', 1)
            ->assertJsonPath('cards.service_ranking.0.label', 'Controllo nutrizionale');

        $response = $this->getJson('/api/v1/dashboard/monthly-trends?year=2026')
            ->assertOk();

        $payload = $response->json();
        $this->assertNotContains('undefined', array_column($payload['monthly_trends'], 'label'));
        $this->assertNotContains('undefined', array_column($payload['professional_split'], 'label'));
        $this->assertNotContains('undefined', array_column($payload['expense_category_split'], 'label'));
        $this->assertSame($secondProfessional->id, $payload['professional_split'][0]['professional_id']);
        $this->assertSame('Bianchi Luca', $payload['professional_split'][0]['professional_name']);
    }

    #[Test]
    public function it_spreads_multimonth_expense_on_competence_months_in_dashboard(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $expenseCategory = ExpenseCategory::factory()->create([
            'name' => 'Utenze',
            'slug' => 'utenze',
        ]);

        ExpenseRecord::query()->create([
            'expense_category_id' => $expenseCategory->id,
            'expense_date' => '2026-05-10',
            'competence_start_date' => '2026-03-01',
            'competence_end_date' => '2026-04-01',
            'competence_month' => 3,
            'competence_year' => 2026,
            'description' => 'Bolletta luce marzo-aprile',
            'type' => 'fixed',
            'amount' => 300,
            'payment_status' => 'da_pagare',
        ]);

        $this->getJson('/api/v1/dashboard/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_fixed_costs', 150)
            ->assertJsonPath('cards.total_variable_costs', 0)
            ->assertJsonPath('cards.total_center_costs', 150);

        $this->getJson('/api/v1/dashboard/summary?month=4&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_fixed_costs', 150)
            ->assertJsonPath('cards.total_variable_costs', 0)
            ->assertJsonPath('cards.total_center_costs', 150);

        $this->getJson('/api/v1/dashboard/summary?month=5&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_fixed_costs', 0)
            ->assertJsonPath('cards.total_variable_costs', 0)
            ->assertJsonPath('cards.total_center_costs', 0);
    }

    #[Test]
    public function it_reflects_direct_cost_on_net_center_margin_cards(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Russo Ilenia',
            'area_name' => 'Nutrizione',
        ]);
        $category = $this->findOrCreateCategory('Nutrizione', 'nutrizione');
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

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 100,
            'direct_cost' => 20,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
        ], $user);

        $this->getJson('/api/v1/dashboard/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_revenue_amount', 100)
            ->assertJsonPath('cards.total_professional_amount', 56)
            ->assertJsonPath('cards.total_center_amount', 24)
            ->assertJsonPath('cards.average_center_gain_performance', 24)
            ->assertJsonPath('cards.total_variable_costs', 76)
            ->assertJsonPath('cards.total_center_costs', 76)
            ->assertJsonPath('cards.net_center_margin', 24);
    }

    #[Test]
    public function it_counts_only_the_remedic_commission_as_revenue_for_provvigione_records(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Russo Ilenia',
            'area_name' => 'Nutrizione',
        ]);
        $category = $this->findOrCreateCategory('Nutrizione', 'nutrizione');
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Controllo nutrizionale provvigione',
            'canonical_name' => 'Controllo nutrizionale provvigione',
            'slug' => 'nutrizione-controllo-provvigione',
        ]);
        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'price_amount' => 300,
            'is_active' => true,
        ]);

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-22',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 300,
            'payment_method' => 'cash',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 20,
            'is_black' => false,
            'is_provvigione' => true,
        ], $user);

        $this->getJson('/api/v1/dashboard/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_performances', 1)
            ->assertJsonPath('cards.performance_type_breakdown.standard', 0)
            ->assertJsonPath('cards.performance_type_breakdown.black', 1)
            ->assertJsonPath('cards.performance_type_breakdown.promo', 0)
            ->assertJsonPath('cards.performance_type_breakdown.provvigione', 1)
            ->assertJsonPath('cards.total_center_amount', 60)
            ->assertJsonPath('cards.total_professional_amount', 0)
            ->assertJsonPath('cards.total_revenue_amount', 60)
            ->assertJsonPath('cards.revenue_payment_breakdown.cash', 60)
            ->assertJsonPath('cards.revenue_payment_breakdown.cash_breakdown.provvigione', 60)
            ->assertJsonPath('cards.professional_amount_breakdown.total', 0)
            ->assertJsonPath('cards.professional_amount_breakdown.fiscal_split.total.provvigione', 0)
            ->assertJsonPath('cards.provvigione_collection_breakdown.total', 60)
            ->assertJsonPath('cards.provvigione_collection_breakdown.collected', 0)
            ->assertJsonPath('cards.provvigione_collection_breakdown.to_collect', 60)
            ->assertJsonPath('cards.net_center_margin', 60);
    }

    #[Test]
    public function it_includes_the_full_competence_month_for_partial_custom_ranges(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $expenseCategory = ExpenseCategory::factory()->create([
            'name' => 'Affitto',
            'slug' => 'affitto',
        ]);

        ExpenseRecord::query()->create([
            'expense_category_id' => $expenseCategory->id,
            'expense_date' => '2026-04-23',
            'competence_start_date' => '2026-04-01',
            'competence_end_date' => '2026-04-01',
            'competence_month' => 4,
            'competence_year' => 2026,
            'description' => 'Affitto aprile',
            'type' => 'fixed',
            'amount' => 30,
            'payment_status' => 'pagata',
        ]);

        $this->getJson('/api/v1/dashboard/summary?date_from=2026-04-20&date_to=2026-04-26')
            ->assertOk()
            ->assertJsonPath('cards.total_fixed_costs', 30)
            ->assertJsonPath('cards.total_variable_costs', 0)
            ->assertJsonPath('cards.total_center_costs', 30);

        $response = $this->getJson('/api/v1/dashboard/monthly-trends?date_from=2026-04-20&date_to=2026-04-26')
            ->assertOk();

        $this->assertEquals(30.0, $response->json('monthly_trends.0.fixed_costs'));
    }

    #[Test]
    public function it_uses_exact_variable_expense_dates_for_partial_custom_ranges(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Russo Ilenia',
            'area_name' => 'Nutrizione',
        ]);
        $category = $this->findOrCreateCategory('Nutrizione', 'nutrizione');
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

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
        ], $user);
        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-25',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(2),
            'quantity' => 2,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
        ], $user);

        $expenseCategory = ExpenseCategory::factory()->create([
            'name' => 'Affitto',
            'slug' => 'affitto',
        ]);
        ExpenseRecord::query()->create([
            'expense_category_id' => $expenseCategory->id,
            'expense_date' => '2026-03-05',
            'competence_start_date' => '2026-03-01',
            'competence_end_date' => '2026-03-01',
            'competence_month' => 3,
            'competence_year' => 2026,
            'description' => 'Affitto marzo',
            'type' => 'fixed',
            'amount' => 30,
            'payment_status' => 'pagata',
        ]);

        $this->getJson('/api/v1/dashboard/summary?date_from=2026-03-20&date_to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('cards.total_performances', 2)
            ->assertJsonPath('cards.total_revenue_amount', 200)
            ->assertJsonPath('cards.total_professional_amount', 140)
            ->assertJsonPath('cards.total_variable_costs', 140)
            ->assertJsonPath('cards.total_fixed_costs', 30)
            ->assertJsonPath('cards.total_center_costs', 170);
    }

    #[Test]
    public function it_reflects_advanced_split_amounts_in_professional_rankings(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $technician = Professional::factory()->create(['full_name' => 'Tecnico EMG', 'area_name' => 'Neurofisiologia']);
        $neurologist = Professional::factory()->create(['full_name' => 'Neurologo EMG', 'area_name' => 'Neurologia']);
        $category = $this->findOrCreateCategory('Neurologia', 'neurologia');
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'EMG',
            'canonical_name' => 'EMG',
            'slug' => 'neurologia-emg',
        ]);
        ProfessionalService::query()->create([
            'professional_id' => $technician->id,
            'service_id' => $service->id,
            'price_amount' => 150,
            'is_active' => true,
        ]);

        app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-10',
            'professional_id' => $technician->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 150,
            'payment_method' => 'card',
            'split_mode' => 'advanced',
            'advanced_splits' => [
                ['subject_type' => 'professional', 'professional_id' => $technician->id, 'amount' => 50],
                ['subject_type' => 'professional', 'professional_id' => $neurologist->id, 'amount' => 70],
                ['subject_type' => 'center', 'amount' => 30],
            ],
        ], $user);

        $this->getJson('/api/v1/dashboard/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_center_amount', 30)
            ->assertJsonPath('cards.total_professional_amount', 120)
            ->assertJsonPath('cards.performance_count_ranking.0.professional_name', 'Neurologo EMG')
            ->assertJsonPath('cards.revenue_ranking.0.professional_name', 'Neurologo EMG')
            ->assertJsonPath('cards.revenue_ranking.0.revenue_total', 150);

        $this->getJson('/api/v1/dashboard/monthly-trends?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('professional_split.0.professional_name', 'Neurologo EMG')
            ->assertJsonPath('professional_split.0.total', 70);
    }

    #[Test]
    public function it_backfills_historical_direct_costs_into_variable_costs(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Russo Ilenia',
            'area_name' => 'Nutrizione',
        ]);
        $category = $this->findOrCreateCategory('Nutrizione', 'nutrizione');
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

        $record = app(PerformanceRecordService::class)->create([
            'performed_at' => '2026-03-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 100,
            'direct_cost' => 20,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
        ], $user);

        ExpenseRecord::query()
            ->where('source_performance_record_id', $record->id)
            ->where('generation_key', 'performance:'.$record->id.':direct-cost')
            ->delete();

        $this->getJson('/api/v1/dashboard/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_professional_amount', 56)
            ->assertJsonPath('cards.total_variable_costs', 56);

        Artisan::call('performance-records:resync-expenses');

        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $record->id,
            'generation_key' => 'performance:'.$record->id.':direct-cost',
            'amount' => 20,
        ]);

        $this->getJson('/api/v1/dashboard/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('cards.total_professional_amount', 56)
            ->assertJsonPath('cards.total_variable_costs', 76);
    }

    private function createPatientIds(int $count): array
    {
        return Patient::factory()
            ->count($count)
            ->create()
            ->pluck('id')
            ->all();
    }

    private function findOrCreateCategory(string $name, string $slug): ServiceCategory
    {
        return ServiceCategory::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );
    }
}
