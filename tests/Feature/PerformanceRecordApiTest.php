<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CashMovement;
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
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-10',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'direct_cost' => 0,
            'payment_method' => 'cash',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => true,
        ])->assertCreated()
            ->assertJsonPath('professional_name_snapshot', 'Bottaro Giuseppe')
            ->assertJsonPath('category_name_snapshot', 'Cardiologia')
            ->assertJsonPath('service_name_snapshot', 'Visita cardiologica')
            ->assertJsonPath('total_amount', '100.00')
            ->assertJsonPath('direct_cost', '0.00')
            ->assertJsonPath('net_divisible_amount', '100.00')
            ->assertJsonPath('professional_amount', '70.00')
            ->assertJsonPath('center_amount', '30.00')
            ->assertJsonPath('visit_shift', 'morning')
            ->assertJsonPath('payment_method', 'cash')
            ->assertJsonPath('payment_status', 'da_pagare')
            ->assertJsonPath('is_black', true)
            ->assertJsonPath('is_promo', false);

        $this->assertDatabaseHas('expense_records', [
            'expense_date' => '2026-04-10 00:00:00',
            'competence_month' => 4,
            'competence_year' => 2026,
            'type' => 'variable',
            'amount' => 70,
            'supplier' => 'Bottaro Giuseppe',
            'payment_status' => 'da_pagare',
        ]);
        $this->assertDatabaseHas('expense_categories', [
            'slug' => 'professionisti',
            'name' => 'Professionisti',
        ]);
    }

    #[Test]
    public function it_saves_multiple_patients_when_quantity_matches(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $firstPatient = Patient::factory()->create();
        $secondPatient = Patient::factory()->create();

        $response = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'quantity' => 2,
            'patient_ids' => [$firstPatient->id, $secondPatient->id],
            'payment_method' => 'cash',
        ]))->assertCreated()
            ->assertJsonPath('quantity', 2)
            ->assertJsonPath('patient_id', $firstPatient->id)
            ->assertJsonPath('patient_ids.0', $firstPatient->id)
            ->assertJsonPath('patient_ids.1', $secondPatient->id);

        $recordId = (int) $response->json('id');

        $this->assertDatabaseHas('patient_performance_record', [
            'performance_record_id' => $recordId,
            'patient_id' => $firstPatient->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('patient_performance_record', [
            'performance_record_id' => $recordId,
            'patient_id' => $secondPatient->id,
            'sort_order' => 1,
        ]);
    }

    #[Test]
    public function it_rejects_when_patient_count_does_not_match_quantity(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'quantity' => 2,
            'patient_ids' => [$patient->id],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_ids']);
    }

    #[Test]
    public function it_keeps_cash_movements_manual_even_for_cash_performance_records(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => false,
            'performed_at' => '2026-04-10',
            'unit_amount' => 135,
        ]))->assertCreated();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => true,
            'performed_at' => '2026-04-11',
            'unit_amount' => 90,
        ]))->assertCreated();

        $this->assertDatabaseCount('cash_movements', 0);
    }

    #[Test]
    public function it_does_not_create_cash_movements_for_card_performance_records(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'card',
            'is_black' => false,
        ]))->assertCreated();

        $this->assertDatabaseCount('cash_movements', 0);
    }

    #[Test]
    public function it_updates_performance_records_without_creating_or_syncing_cash_movements(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $created = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => false,
            'performed_at' => '2026-04-10',
            'unit_amount' => 100,
        ]))->assertCreated()->json();

        $this->assertDatabaseCount('cash_movements', 0);

        $this->putJson('/api/v1/performance-records/'.$created['id'], $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => true,
            'performed_at' => '2026-04-12',
            'unit_amount' => 160,
            'direct_cost' => 20,
            'notes' => 'Aggiornamento test',
        ]))->assertOk()
            ->assertJsonPath('direct_cost', '20.00')
            ->assertJsonPath('net_divisible_amount', '140.00')
            ->assertJsonPath('professional_amount', '98.00')
            ->assertJsonPath('center_amount', '42.00');

        $linkedExpenses = ExpenseRecord::query()
            ->where('source_performance_record_id', $created['id'])
            ->get()
            ->keyBy('generation_key');

        $this->assertCount(2, $linkedExpenses);
        $this->assertSame('2026-04-12', $linkedExpenses['performance:'.$created['id'].':standard']->expense_date?->toDateString());
        $this->assertSame(4, $linkedExpenses['performance:'.$created['id'].':standard']->competence_month);
        $this->assertSame(2026, $linkedExpenses['performance:'.$created['id'].':standard']->competence_year);
        $this->assertSame('Bottaro Giuseppe', $linkedExpenses['performance:'.$created['id'].':standard']->supplier);
        $this->assertSame('98.00', $linkedExpenses['performance:'.$created['id'].':standard']->amount);
        $this->assertSame('20.00', $linkedExpenses['performance:'.$created['id'].':direct-cost']->amount);
        $this->assertNull($linkedExpenses['performance:'.$created['id'].':direct-cost']->supplier);
        $this->assertSame('variable', $linkedExpenses['performance:'.$created['id'].':direct-cost']->type->value);

        $this->putJson('/api/v1/performance-records/'.$created['id'], $this->performancePayload($professional, $service, [
            'payment_method' => 'card',
            'is_black' => false,
            'performed_at' => '2026-04-12',
            'unit_amount' => 160,
        ]))->assertOk();

        $this->assertDatabaseCount('cash_movements', 0);
    }

    #[Test]
    public function it_deletes_performance_records_without_touching_manual_cash_movements(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $manualMovement = CashMovement::query()->create([
            'movement_date' => '2026-04-08',
            'cash_box_type' => 'fatturati',
            'movement_type' => 'versamento',
            'counterparty_name' => 'Manuale',
            'amount' => '50.00',
            'reason' => 'Fondo cassa',
            'notes' => null,
            'balance_after' => '50.00',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $created = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => false,
        ]))->assertCreated()->json();

        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $created['id'],
        ]);

        $this->deleteJson('/api/v1/performance-records/'.$created['id'])
            ->assertNoContent();

        $this->assertDatabaseHas('cash_movements', [
            'id' => $manualMovement->id,
        ]);
        $this->assertDatabaseMissing('expense_records', [
            'source_performance_record_id' => $created['id'],
        ]);
    }

    #[Test]
    public function it_rejects_fixed_amount_higher_than_total_amount(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create();
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-10',
            'professional_id' => $professional->id,
            'service_name' => 'Prestazione manuale',
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'calculation_mode' => 'fixed',
            'fixed_amount' => 120,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['fixed_amount']);
    }

    #[Test]
    public function it_applies_direct_cost_before_percentage_split(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $response = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'unit_amount' => 100,
            'direct_cost' => 20,
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
        ]))->assertCreated()
            ->assertJsonPath('total_amount', '100.00')
            ->assertJsonPath('direct_cost', '20.00')
            ->assertJsonPath('net_divisible_amount', '80.00')
            ->assertJsonPath('professional_amount', '56.00')
            ->assertJsonPath('center_amount', '24.00');

        $recordId = (int) $response->json('id');
        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $recordId,
            'generation_key' => 'performance:'.$recordId.':standard',
            'amount' => 56,
        ]);
        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $recordId,
            'generation_key' => 'performance:'.$recordId.':direct-cost',
            'amount' => 20,
        ]);
    }

    #[Test]
    public function it_creates_an_advanced_split_record_for_emg_and_syncs_professional_expenses(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $technician, 'service' => $service] = $this->createProfessionalServiceContext();
        $neurologist = Professional::factory()->create([
            'full_name' => 'Verdi Marta',
            'area_name' => 'Neurologia',
        ]);
        $patient = Patient::factory()->create();

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-18',
            'professional_id' => $technician->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 170,
            'direct_cost' => 20,
            'payment_method' => 'card',
            'split_mode' => 'advanced',
            'advanced_splits' => [
                ['subject_type' => 'professional', 'professional_id' => $technician->id, 'amount' => 50],
                ['subject_type' => 'professional', 'professional_id' => $neurologist->id, 'amount' => 70],
                ['subject_type' => 'center', 'amount' => 30],
            ],
        ])->assertCreated()
            ->assertJsonPath('split_mode', 'advanced')
            ->assertJsonPath('total_amount', '170.00')
            ->assertJsonPath('direct_cost', '20.00')
            ->assertJsonPath('net_divisible_amount', '150.00')
            ->assertJsonPath('professional_amount', '120.00')
            ->assertJsonPath('center_amount', '30.00')
            ->assertJsonCount(3, 'advanced_splits');

        $recordId = (int) $response->json('id');

        $this->assertDatabaseCount('performance_record_splits', 3);
        $this->assertDatabaseHas('performance_record_splits', [
            'performance_record_id' => $recordId,
            'subject_type' => 'professional',
            'professional_id' => $technician->id,
            'amount' => 50,
        ]);
        $this->assertDatabaseHas('performance_record_splits', [
            'performance_record_id' => $recordId,
            'subject_type' => 'professional',
            'professional_id' => $neurologist->id,
            'amount' => 70,
        ]);
        $this->assertDatabaseHas('performance_record_splits', [
            'performance_record_id' => $recordId,
            'subject_type' => 'center',
            'professional_id' => null,
            'amount' => 30,
        ]);

        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $recordId,
            'supplier' => 'Bottaro Giuseppe',
            'amount' => 50,
        ]);
        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $recordId,
            'supplier' => 'Verdi Marta',
            'amount' => 70,
        ]);
        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $recordId,
            'generation_key' => 'performance:'.$recordId.':direct-cost',
            'amount' => 20,
        ]);
    }

    #[Test]
    public function it_syncs_payment_status_from_performance_record_to_linked_expenses(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $created = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_status' => 'pagata',
        ]))->assertCreated()
            ->assertJsonPath('payment_status', 'pagata')
            ->json();

        $expense = ExpenseRecord::query()->where('source_performance_record_id', $created['id'])->firstOrFail();
        $this->assertSame('pagata', $expense->payment_status->value);

        $this->putJson('/api/v1/performance-records/'.$created['id'], $this->performancePayload($professional, $service, [
            'payment_status' => 'da_pagare',
        ]))->assertOk()
            ->assertJsonPath('payment_status', 'da_pagare');

        $this->assertDatabaseHas('performance_records', [
            'id' => $created['id'],
            'payment_status' => 'da_pagare',
        ]);
        $this->assertDatabaseHas('expense_records', [
            'id' => $expense->id,
            'payment_status' => 'da_pagare',
        ]);
    }

    #[Test]
    public function it_rejects_direct_cost_higher_than_total_amount(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'unit_amount' => 100,
            'direct_cost' => 101,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['direct_cost']);
    }

    #[Test]
    public function it_rejects_decimal_money_inputs_for_performance_records(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'unit_amount' => '100.50',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_amount']);

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'direct_cost' => '20.50',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['direct_cost']);

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'calculation_mode' => 'fixed',
            'fixed_amount' => '30.50',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['fixed_amount']);

        $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-18',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 150,
            'direct_cost' => 0,
            'payment_method' => 'card',
            'split_mode' => 'advanced',
            'advanced_splits' => [
                ['subject_type' => 'professional', 'professional_id' => $professional->id, 'amount' => '50.50'],
                ['subject_type' => 'center', 'amount' => '99.50'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['advanced_splits.0.amount', 'advanced_splits.1.amount']);
    }

    #[Test]
    public function it_rejects_decimal_quantities(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'quantity' => 1.5,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    #[Test]
    public function it_rejects_black_records_marked_as_invoiced(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'is_black' => true,
            'is_invoiced' => true,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['is_black', 'is_invoiced']);
    }

    #[Test]
    public function it_rejects_black_records_paid_by_card(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'is_black' => true,
            'payment_method' => 'card',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_method']);
    }

    #[Test]
    public function it_persists_promo_flag_on_performance_records(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $response = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'is_promo' => true,
            'payment_method' => 'cash',
        ]))->assertCreated()
            ->assertJsonPath('is_black', false)
            ->assertJsonPath('is_promo', true);

        $recordId = (int) $response->json('id');

        $this->assertDatabaseHas('performance_records', [
            'id' => $recordId,
            'is_promo' => true,
        ]);

        $this->putJson('/api/v1/performance-records/'.$recordId, $this->performancePayload($professional, $service, [
            'is_promo' => false,
            'payment_method' => 'cash',
        ]))->assertOk()
            ->assertJsonPath('is_promo', false);

        $this->assertDatabaseHas('performance_records', [
            'id' => $recordId,
            'is_promo' => false,
        ]);
    }

    #[Test]
    public function it_rejects_records_marked_as_black_and_promo_together(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'is_black' => true,
            'is_promo' => true,
            'payment_method' => 'cash',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['is_black', 'is_promo']);
    }

    #[Test]
    public function it_filters_performance_records_by_invoice_liquidation_and_fiscal_flags(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $invoicedWhiteLiquidated = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'performed_at' => '2026-04-10',
            'payment_status' => 'pagata',
            'is_invoiced' => true,
            'is_black' => false,
            'notes' => 'invoiced-white-liquidated',
        ]))->assertCreated()->json();

        $notInvoicedWhiteNotLiquidated = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'performed_at' => '2026-04-11',
            'payment_status' => 'da_pagare',
            'is_invoiced' => false,
            'is_black' => false,
            'notes' => 'not-invoiced-white-not-liquidated',
        ]))->assertCreated()->json();

        $blackNotLiquidated = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'performed_at' => '2026-04-12',
            'payment_status' => 'da_pagare',
            'is_invoiced' => false,
            'is_black' => true,
            'payment_method' => 'cash',
            'notes' => 'black-not-liquidated',
        ]))->assertCreated()->json();

        $this->getJson('/api/v1/performance-records?invoice_filter=invoiced')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoicedWhiteLiquidated['id']);

        $this->getJson('/api/v1/performance-records?invoice_filter=not_invoiced')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notInvoicedWhiteNotLiquidated['id']);

        $this->getJson('/api/v1/performance-records?liquidation_filter=liquidated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoicedWhiteLiquidated['id']);

        $this->getJson('/api/v1/performance-records?liquidation_filter=not_liquidated')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/performance-records?fiscal_filter=white')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/performance-records?fiscal_filter=black')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $blackNotLiquidated['id']);

        $this->getJson('/api/v1/performance-records?liquidation_filter=not_liquidated&fiscal_filter=white')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notInvoicedWhiteNotLiquidated['id']);

        $this->getJson('/api/v1/performance-records?invoice_filter=not_invoiced&fiscal_filter=black')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $blackNotLiquidated['id']);
    }

    #[Test]
    public function it_returns_filtered_center_and_professional_totals_independent_from_pagination(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'performed_at' => '2026-04-10',
            'unit_amount' => 100,
            'percentage_value' => 60,
            'notes' => 'totals-a',
        ]))->assertCreated();

        $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'performed_at' => '2026-04-11',
            'unit_amount' => 200,
            'percentage_value' => 25,
            'notes' => 'totals-b',
        ]))->assertCreated();

        $this->getJson('/api/v1/performance-records?date_from=2026-04-01&date_to=2026-04-30&per_page=1&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.center_share', 190)
            ->assertJsonPath('totals.professional_share', 110);
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

    private function performancePayload(Professional $professional, Service $service, array $overrides = []): array
    {
        $defaultPatientId = Patient::factory()->create()->id;

        return array_merge([
            'performed_at' => '2026-04-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$defaultPatientId],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
            'notes' => null,
        ], $overrides);
    }
}
