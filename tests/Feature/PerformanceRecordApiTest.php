<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CashMovement;
use App\Models\ExpenseRecord;
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
    public function it_creates_automatic_cash_movements_for_cash_performance_records_in_the_expected_cash_box(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $response = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => false,
            'performed_at' => '2026-04-10',
            'unit_amount' => 135,
        ]))->assertCreated();

        $performanceRecordId = $response->json('id');

        $this->assertDatabaseHas('cash_movements', [
            'source_performance_record_id' => $performanceRecordId,
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => '135.00',
            'counterparty_name' => 'Bottaro Giuseppe',
        ]);

        $movement = CashMovement::query()->where('source_performance_record_id', $performanceRecordId)->firstOrFail();

        $this->assertSame('Incasso prestazione: Visita cardiologica', $movement->reason);
        $this->assertStringContainsString('Generato automaticamente dalla prestazione effettuata', (string) $movement->notes);

        $blackResponse = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => true,
            'performed_at' => '2026-04-11',
            'unit_amount' => 90,
        ]))->assertCreated();

        $this->assertDatabaseHas('cash_movements', [
            'source_performance_record_id' => $blackResponse->json('id'),
            'movement_type' => 'versamento',
            'cash_box_type' => 'black',
            'amount' => '90.00',
        ]);
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
    public function it_updates_the_linked_cash_movement_and_removes_it_when_payment_switches_to_card(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $created = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => false,
            'performed_at' => '2026-04-10',
            'unit_amount' => 100,
        ]))->assertCreated()->json();

        $this->putJson('/api/v1/performance-records/'.$created['id'], $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => true,
            'performed_at' => '2026-04-12',
            'unit_amount' => 160,
            'notes' => 'Aggiornamento test',
        ]))->assertOk();

        $movement = CashMovement::query()->where('source_performance_record_id', $created['id'])->firstOrFail();

        $this->assertSame('black', $movement->cash_box_type->value);
        $this->assertSame('160.00', $movement->amount);
        $this->assertSame('2026-04-12', $movement->movement_date?->toDateString());
        $this->assertDatabaseCount('cash_movements', 1);

        $expense = ExpenseRecord::query()->where('source_performance_record_id', $created['id'])->firstOrFail();
        $this->assertSame('2026-04-12', $expense->expense_date?->toDateString());
        $this->assertSame(4, $expense->competence_month);
        $this->assertSame(2026, $expense->competence_year);
        $this->assertSame('Bottaro Giuseppe', $expense->supplier);
        $this->assertSame('112.00', $expense->amount);
        $this->assertSame('variable', $expense->type->value);

        $this->putJson('/api/v1/performance-records/'.$created['id'], $this->performancePayload($professional, $service, [
            'payment_method' => 'card',
            'is_black' => true,
            'performed_at' => '2026-04-12',
            'unit_amount' => 160,
        ]))->assertOk();

        $this->assertDatabaseMissing('cash_movements', [
            'source_performance_record_id' => $created['id'],
        ]);
    }

    #[Test]
    public function it_deletes_the_linked_cash_movement_when_the_performance_record_is_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $created = $this->postJson('/api/v1/performance-records', $this->performancePayload($professional, $service, [
            'payment_method' => 'cash',
            'is_black' => false,
        ]))->assertCreated()->json();

        $this->assertDatabaseHas('cash_movements', [
            'source_performance_record_id' => $created['id'],
        ]);
        $this->assertDatabaseHas('expense_records', [
            'source_performance_record_id' => $created['id'],
        ]);

        $this->deleteJson('/api/v1/performance-records/'.$created['id'])
            ->assertNoContent();

        $this->assertDatabaseMissing('cash_movements', [
            'source_performance_record_id' => $created['id'],
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

    private function createProfessionalServiceContext(): array
    {
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

        return [
            'professional' => $professional,
            'service' => $service,
        ];
    }

    private function performancePayload(Professional $professional, Service $service, array $overrides = []): array
    {
        return array_merge([
            'performed_at' => '2026-04-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'notes' => null,
        ], $overrides);
    }
}
