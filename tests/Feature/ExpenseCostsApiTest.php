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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpenseCostsApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_accepts_decimal_amounts_for_expense_records(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ExpenseCategory::factory()->create();

        $response = $this->postJson('/api/v1/expense-records', [
            'expense_category_id' => $category->id,
            'expense_date' => '2026-04-26',
            'description' => 'Test costo',
            'type' => 'fixed',
            'amount' => 123.45,
            'payment_status' => 'da_pagare',
        ])->assertCreated();

        $this->assertSame('123.45', $response->json('amount'));

        $commaResponse = $this->postJson('/api/v1/expense-records', [
            'expense_category_id' => $category->id,
            'expense_date' => '2026-04-27',
            'description' => 'Test costo con virgola',
            'type' => 'variable',
            'amount' => '98,70',
            'payment_status' => 'pagata',
        ])->assertCreated();

        $this->assertSame('98.70', $commaResponse->json('amount'));
    }

    #[Test]
    public function it_does_not_expose_automatic_cost_template_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->getJson('/api/v1/expense-templates')->assertNotFound();
    }

    #[Test]
    public function it_creates_monthly_competence_allocations_for_multimonth_expense(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $category = ExpenseCategory::factory()->create();

        $response = $this->postJson('/api/v1/expense-records', [
            'expense_category_id' => $category->id,
            'expense_date' => '2026-05-10',
            'competence_start_date' => '2026-03-01',
            'competence_end_date' => '2026-05-01',
            'description' => 'Costo plurimensile test',
            'type' => 'fixed',
            'amount' => 100,
            'payment_status' => 'da_pagare',
        ])->assertCreated();

        $expenseId = (int) $response->json('id');

        $this->assertDatabaseHas('expense_record_competences', [
            'expense_record_id' => $expenseId,
            'competence_year' => 2026,
            'competence_month' => 3,
            'allocated_amount' => '33.34',
        ]);
        $this->assertDatabaseHas('expense_record_competences', [
            'expense_record_id' => $expenseId,
            'competence_year' => 2026,
            'competence_month' => 4,
            'allocated_amount' => '33.33',
        ]);
        $this->assertDatabaseHas('expense_record_competences', [
            'expense_record_id' => $expenseId,
            'competence_year' => 2026,
            'competence_month' => 5,
            'allocated_amount' => '33.33',
        ]);
    }

    #[Test]
    public function it_rejects_expense_records_with_competence_end_before_competence_start(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $category = ExpenseCategory::factory()->create();

        $this->postJson('/api/v1/expense-records', [
            'expense_category_id' => $category->id,
            'expense_date' => '2026-05-10',
            'competence_start_date' => '2026-04-01',
            'competence_end_date' => '2026-03-01',
            'description' => 'Costo con competenza incoerente',
            'type' => 'fixed',
            'amount' => 100,
            'payment_status' => 'da_pagare',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['competence_end_date']);
    }

    #[Test]
    public function it_syncs_performance_payment_status_when_updating_a_linked_expense(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $performance = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-26',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [Patient::factory()->create()->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
        ])->assertCreated()->json();

        $expense = ExpenseRecord::query()->where('source_performance_record_id', $performance['id'])->firstOrFail();

        $this->putJson('/api/v1/expense-records/'.$expense->id, [
            'expense_category_id' => $expense->expense_category_id,
            'expense_date' => $expense->expense_date?->toDateString(),
            'competence_start_date' => $expense->competence_start_date?->toDateString(),
            'competence_end_date' => $expense->competence_end_date?->toDateString(),
            'description' => $expense->description,
            'type' => $expense->type->value,
            'amount' => $expense->amount,
            'supplier' => $expense->supplier,
            'payment_status' => 'pagata',
            'notes' => $expense->notes,
        ])->assertOk()
            ->assertJsonPath('payment_status', 'pagata');

        $this->assertDatabaseHas('performance_records', [
            'id' => $performance['id'],
            'payment_status' => 'pagata',
        ]);
        $this->assertDatabaseHas('expense_records', [
            'id' => $expense->id,
            'payment_status' => 'pagata',
        ]);
    }

    #[Test]
    public function it_blocks_deletion_of_expense_records_linked_to_performance_records(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $performance = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-27',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [Patient::factory()->create()->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
        ])->assertCreated()->json();

        $expense = ExpenseRecord::query()->where('source_performance_record_id', $performance['id'])->firstOrFail();

        $this->deleteJson('/api/v1/expense-records/'.$expense->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expense_record']);
    }

    #[Test]
    public function it_returns_filtered_cost_totals_independently_from_list_pagination(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ExpenseCategory::factory()->create();

        foreach (range(1, 25) as $day) {
            ExpenseRecord::query()->create([
                'expense_category_id' => $category->id,
                'expense_date' => sprintf('2026-03-%02d', $day),
                'competence_start_date' => '2026-03-01',
                'competence_end_date' => '2026-03-01',
                'competence_months_count' => 1,
                'competence_month' => 3,
                'competence_year' => 2026,
                'description' => sprintf('Costo variabile marzo %02d', $day),
                'type' => 'variable',
                'amount' => 10,
                'payment_status' => 'da_pagare',
            ]);
        }

        ExpenseRecord::query()->create([
            'expense_category_id' => $category->id,
            'expense_date' => '2026-03-26',
            'competence_start_date' => '2026-03-01',
            'competence_end_date' => '2026-03-01',
            'competence_months_count' => 1,
            'competence_month' => 3,
            'competence_year' => 2026,
            'description' => 'Costo fisso marzo',
            'type' => 'fixed',
            'amount' => 40,
            'payment_status' => 'pagata',
        ]);

        ExpenseRecord::query()->create([
            'expense_category_id' => $category->id,
            'expense_date' => '2026-04-02',
            'competence_start_date' => '2026-04-01',
            'competence_end_date' => '2026-04-01',
            'competence_months_count' => 1,
            'competence_month' => 4,
            'competence_year' => 2026,
            'description' => 'Costo aprile fuori filtro',
            'type' => 'variable',
            'amount' => 99,
            'payment_status' => 'da_pagare',
        ]);

        $pageResponse = $this->getJson('/api/v1/expense-records?month=3&year=2026&per_page=20')
            ->assertOk();

        $pageTotal = collect($pageResponse->json('data'))
            ->sum(fn (array $record) => (float) $record['amount']);

        $this->assertCount(20, $pageResponse->json('data'));
        $this->assertSame(230.0, $pageTotal);

        $this->getJson('/api/v1/expense-records/summary?month=3&year=2026')
            ->assertOk()
            ->assertJsonPath('totals.records_count', 26)
            ->assertJsonPath('totals.total_costs', 290)
            ->assertJsonPath('totals.fixed_costs', 40)
            ->assertJsonPath('totals.variable_costs', 250)
            ->assertJsonPath('totals.unpaid_costs', 250);
    }

    private function createProfessionalServiceContext(): array
    {
        $professional = Professional::factory()->create([
            'full_name' => 'Bottaro Giuseppe',
            'area_name' => 'Cardiologia',
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

        return [
            'professional' => $professional,
            'service' => $service,
        ];
    }
}
