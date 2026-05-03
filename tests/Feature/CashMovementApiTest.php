<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\CashMovementDeletedWarningMail;
use App\Models\CashMovement;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashMovementApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_movements_and_keeps_the_two_cash_boxes_separated(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 100,
            'reason' => 'Incasso giornata',
        ])->assertCreated()
            ->assertJsonPath('counterparty_name', 'Incasso giornata')
            ->assertJsonPath('balance_after', '100.00');

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'black',
            'amount' => 40,
            'reason' => 'Contante partner',
        ])->assertCreated()
            ->assertJsonPath('balance_after', '40.00');

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-11',
            'movement_type' => 'prelievo',
            'cash_box_type' => 'fatturati',
            'counterparty_name' => 'Fondo cassa',
            'amount' => 25,
            'reason' => 'Piccole spese',
        ])->assertCreated()
            ->assertJsonPath('balance_after', '75.00');

        $this->getJson('/api/v1/cash-movements/summary?date_from=2026-04-01&date_to=2026-04-30')
            ->assertOk()
            ->assertJsonPath('boxes.fatturati.current_balance', '75.00')
            ->assertJsonPath('boxes.black.current_balance', '40.00')
            ->assertJsonPath('totals.current_balance', '115.00')
            ->assertJsonPath('boxes.fatturati.period_movements_count', 2)
            ->assertJsonPath('boxes.black.period_movements_count', 1);
    }

    #[Test]
    public function it_blocks_withdrawals_that_would_make_a_cash_box_negative(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-10',
            'movement_type' => 'prelievo',
            'cash_box_type' => 'black',
            'counterparty_name' => 'Prelievo non coperto',
            'amount' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function it_recalculates_running_balances_after_an_update(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $first = $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 100,
        ])->assertCreated()->json();

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-12',
            'movement_type' => 'prelievo',
            'cash_box_type' => 'fatturati',
            'amount' => 30,
        ])->assertCreated();

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-13',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 20,
        ])->assertCreated();

        $this->putJson('/api/v1/cash-movements/'.$first['id'], [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 60,
        ])->assertOk()
            ->assertJsonPath('balance_after', '60.00');

        $balances = CashMovement::query()
            ->where('cash_box_type', 'fatturati')
            ->orderBy('movement_date')
            ->orderBy('id')
            ->pluck('balance_after')
            ->all();

        $this->assertSame(['60.00', '30.00', '50.00'], $balances);
    }

    #[Test]
    public function it_blocks_deletions_that_would_break_the_running_balance(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $deposit = $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 50,
        ])->assertCreated()->json();

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-12',
            'movement_type' => 'prelievo',
            'cash_box_type' => 'fatturati',
            'amount' => 40,
        ])->assertCreated();

        $this->deleteJson('/api/v1/cash-movements/'.$deposit['id'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['movement']);
    }

    #[Test]
    public function it_sends_warning_emails_when_a_manual_cash_movement_is_deleted(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'name' => 'Mario Rossi',
            'email' => 'mario.rossi@example.test',
        ]);
        Sanctum::actingAs($user);

        $movement = $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 100,
            'reason' => 'Incasso giornata',
            'notes' => 'Eliminazione di prova',
        ])->assertCreated()->json();

        $this->deleteJson('/api/v1/cash-movements/'.$movement['id'])
            ->assertNoContent();

        $this->assertDatabaseMissing('cash_movements', [
            'id' => $movement['id'],
        ]);

        Mail::assertSent(CashMovementDeletedWarningMail::class, function (CashMovementDeletedWarningMail $mail) use ($movement): bool {
            return $mail->hasTo('humancaretelemedicine@gmail.com')
                && $mail->hasTo('camillomusmeci.dev@gmail.com')
                && $mail->details['movement_id'] === $movement['id']
                && $mail->details['movement_type_label'] === 'Versamento'
                && str_contains($mail->details['actor_label'], 'mario.rossi@example.test');
        });
    }

    #[Test]
    public function it_resets_all_cash_movements(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 100,
            'reason' => 'Incasso giornata',
        ])->assertCreated();

        $this->postJson('/api/v1/cash-movements', [
            'movement_date' => '2026-04-11',
            'movement_type' => 'versamento',
            'cash_box_type' => 'black',
            'amount' => 40,
            'reason' => 'Contanti black',
        ])->assertCreated();

        $this->postJson('/api/v1/cash-movements/reset')
            ->assertOk()
            ->assertJsonPath('deleted_count', 2);

        $this->assertDatabaseCount('cash_movements', 0);

        $this->getJson('/api/v1/cash-movements/summary')
            ->assertOk()
            ->assertJsonPath('totals.current_balance', '0.00');
    }

    #[Test]
    public function it_allows_updating_and_deleting_legacy_linked_movements_manually(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();

        $performanceRecord = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-10',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
        ])->assertCreated()->json();

        $movement = CashMovement::query()->create([
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'counterparty_name' => 'Legacy link',
            'amount' => '100.00',
            'reason' => 'Movimento legacy',
            'notes' => 'Collegamento storico',
            'source_performance_record_id' => $performanceRecord['id'],
            'balance_after' => '100.00',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->putJson('/api/v1/cash-movements/'.$movement->id, [
            'movement_date' => '2026-04-10',
            'movement_type' => 'versamento',
            'cash_box_type' => 'fatturati',
            'amount' => 120,
            'reason' => 'Movimento legacy aggiornato',
        ])->assertOk()
            ->assertJsonPath('amount', '120.00');

        $this->deleteJson('/api/v1/cash-movements/'.$movement->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('cash_movements', [
            'id' => $movement->id,
        ]);
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
}
