<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfessionalAvailabilityManualApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_only_internal_manual_rules_and_exceptions(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create();

        \App\Models\ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'manual',
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'is_active' => true,
        ]);

        \App\Models\ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'weekday' => 2,
            'start_time' => '15:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        \App\Models\ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'manual',
            'date' => '2026-06-29',
            'type' => 'available',
            'start_time' => '15:30:00',
            'end_time' => '17:30:00',
        ]);

        \App\Models\ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-19',
            'type' => 'available',
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
        ]);

        $this->getJson('/api/v1/professional-availabilities?professional_id='.$professional->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.source', 'manual');

        $this->getJson('/api/v1/professional-availability-exceptions?professional_id='.$professional->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.source', 'manual');
    }

    #[Test]
    public function it_rejects_overlapping_manual_recurring_rules(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create();

        $this->postJson('/api/v1/professional-availabilities', [
            'professional_id' => $professional->id,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'is_active' => true,
            'notes' => null,
        ])->assertCreated();

        $this->postJson('/api/v1/professional-availabilities', [
            'professional_id' => $professional->id,
            'weekday' => 1,
            'start_time' => '12:00',
            'end_time' => '15:00',
            'is_active' => true,
            'notes' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    #[Test]
    public function it_clears_only_miodottore_imported_rows(): void
    {
        $professional = Professional::factory()->create();

        \App\Models\ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'manual',
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'is_active' => true,
        ]);

        \App\Models\ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'weekday' => 2,
            'start_time' => '15:00',
            'end_time' => '18:00',
            'is_active' => true,
        ]);

        \App\Models\ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-19',
            'type' => 'available',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);

        $this->artisan('remedic:clear-miodottore-availability-data', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('professional_availability_rules', 2);
        $this->assertDatabaseCount('professional_availability_exceptions', 1);

        $this->artisan('remedic:clear-miodottore-availability-data')
            ->assertSuccessful();

        $this->assertDatabaseCount('professional_availability_rules', 1);
        $this->assertDatabaseMissing('professional_availability_rules', ['source' => 'miodottore']);
        $this->assertDatabaseCount('professional_availability_exceptions', 0);
    }
}
