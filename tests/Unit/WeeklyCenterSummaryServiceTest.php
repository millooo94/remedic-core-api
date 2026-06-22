<?php

namespace Tests\Unit;

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
use App\Services\WeeklyCenterSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WeeklyCenterSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_the_weekly_center_summary_for_the_last_completed_week(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $professional = Professional::factory()->create([
            'full_name' => 'Bottaro Giuseppe',
            'area_name' => 'Cardiologia',
        ]);
        $serviceCategory = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'sort_order' => 1],
        );
        $service = Service::factory()->create([
            'category_id' => $serviceCategory->id,
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
            'performed_at' => '2026-04-22',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => Patient::factory()->count(2)->create()->pluck('id')->all(),
            'quantity' => 2,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 60,
            'is_black' => false,
        ], $user);

        $expenseCategory = ExpenseCategory::factory()->create([
            'name' => 'Affitto',
            'slug' => 'affitto',
        ]);
        ExpenseRecord::query()->create([
            'expense_category_id' => $expenseCategory->id,
            'expense_date' => '2026-04-23',
            'competence_month' => 4,
            'competence_year' => 2026,
            'description' => 'Affitto settimana',
            'type' => 'fixed',
            'amount' => 30,
            'payment_status' => 'pagata',
        ]);

        $summary = app(WeeklyCenterSummaryService::class)->buildSummary(Carbon::parse('2026-04-27'));

        $this->assertSame('2026-04-20', $summary['period']['start_date']);
        $this->assertSame('2026-04-26', $summary['period']['end_date']);
        $this->assertSame(2, $summary['kpis']['total_performances']);
        $this->assertSame(200.0, $summary['kpis']['total_revenue_amount']);
        $this->assertSame(120.0, $summary['kpis']['total_professional_amount']);
        $this->assertSame(80.0, $summary['kpis']['total_center_amount']);
        $this->assertSame(30.0, $summary['kpis']['total_fixed_costs']);
        $this->assertSame(120.0, $summary['kpis']['total_variable_costs']);
        $this->assertSame(150.0, $summary['kpis']['total_center_costs']);
        $this->assertSame(50.0, $summary['kpis']['net_center_margin']);
    }
}
