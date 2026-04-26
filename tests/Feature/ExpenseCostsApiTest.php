<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ExpenseCategory;
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
    public function it_forces_fixed_type_for_automatic_cost_templates(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ExpenseCategory::factory()->create();

        $response = $this->postJson('/api/v1/expense-templates', [
            'category_id' => $category->id,
            'name' => 'Canone struttura',
            'type' => 'variable',
            'recurrence' => 'monthly',
            'default_amount' => '120,75',
            'start_date' => '2026-04-01',
            'day_of_generation' => 1,
            'is_active' => true,
        ])->assertCreated();

        $this->assertSame('fixed', $response->json('type'));

        $templateId = (int) $response->json('id');
        $this->assertDatabaseHas('expense_templates', [
            'id' => $templateId,
            'type' => 'fixed',
            'default_amount' => '120.75',
        ]);
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
}
