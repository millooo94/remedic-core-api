<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityException;
use App\Models\ProfessionalAvailabilityRule;
use App\Models\ProfessionalService;
use App\Models\ProfessionalTimeBlock;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_appointments_only_inside_professional_availability_without_overlaps_or_blocks(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Rossi Mario',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'area_name' => 'Cardiologia',
        ]);
        $patient = Patient::factory()->create();
        $category = ServiceCategory::factory()->create([
            'name' => 'Agenda Cardiologia',
            'slug' => 'agenda-cardiologia',
        ]);
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Visita agenda',
            'canonical_name' => 'Visita agenda',
            'slug' => 'visita-agenda',
        ]);

        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'price_amount' => 100,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'is_active' => true,
        ]);

        $payload = [
            'patient_id' => $patient->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T09:00:00',
            'ends_at' => '2026-06-15T09:30:00',
            'status' => 'prenotato',
            'notes' => 'Prima visita',
        ];

        $this->postJson('/api/v1/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('patient_id', $patient->id)
            ->assertJsonPath('professional_id', $professional->id)
            ->assertJsonPath('status', 'prenotato');

        $this->postJson('/api/v1/appointments', [
            ...$payload,
            'starts_at' => '2026-06-15T08:00:00',
            'ends_at' => '2026-06-15T08:30:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->postJson('/api/v1/appointments', [
            ...$payload,
            'starts_at' => '2026-06-15T09:15:00',
            'ends_at' => '2026-06-15T09:45:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        ProfessionalTimeBlock::query()->create([
            'professional_id' => $professional->id,
            'starts_at' => '2026-06-15T10:00:00',
            'ends_at' => '2026-06-15T11:00:00',
            'type' => 'blocco',
        ]);

        $this->postJson('/api/v1/appointments', [
            ...$payload,
            'starts_at' => '2026-06-15T10:15:00',
            'ends_at' => '2026-06-15T10:45:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    #[Test]
    public function it_allows_creating_appointments_when_a_professional_has_no_availability_rules_configured(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Bianchi Laura',
            'first_name' => 'Laura',
            'last_name' => 'Bianchi',
            'area_name' => 'Dermatologia',
        ]);
        $patient = Patient::factory()->create();
        $category = ServiceCategory::factory()->create([
            'name' => 'Agenda Dermatologia',
            'slug' => 'agenda-dermatologia',
        ]);
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Controllo agenda',
            'canonical_name' => 'Controllo agenda',
            'slug' => 'controllo-agenda',
        ]);

        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'price_amount' => 80,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        $payload = [
            'patient_id' => $patient->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T11:00:00',
            'ends_at' => '2026-06-16T11:30:00',
            'status' => 'prenotato',
            'notes' => 'Appuntamento senza regole configurate',
        ];

        $this->postJson('/api/v1/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('professional_id', $professional->id);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'date' => '2026-06-16',
            'type' => 'unavailable',
            'start_time' => '10:30:00',
            'end_time' => '12:00:00',
        ]);

        $this->postJson('/api/v1/appointments', [
            ...$payload,
            'starts_at' => '2026-06-16T11:15:00',
            'ends_at' => '2026-06-16T11:45:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    #[Test]
    public function it_ignores_miodottore_availability_rows_when_validating_internal_agenda_slots(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create([
            'full_name' => 'Verdi Giulia',
            'first_name' => 'Giulia',
            'last_name' => 'Verdi',
            'area_name' => 'Neurologia',
        ]);
        $patient = Patient::factory()->create();
        $category = ServiceCategory::factory()->create([
            'name' => 'Agenda Neurologia',
            'slug' => 'agenda-neurologia',
        ]);
        $service = Service::factory()->create(['category_id' => $category->id]);

        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'price_amount' => 120,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'weekday' => 3,
            'start_time' => '08:00',
            'end_time' => '09:00',
            'is_active' => true,
        ]);

        $payload = [
            'patient_id' => $patient->id,
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'starts_at' => '2026-06-17T10:00:00',
            'ends_at' => '2026-06-17T10:30:00',
            'status' => 'prenotato',
            'notes' => 'Test agenda interna',
        ];

        $this->postJson('/api/v1/appointments', $payload)
            ->assertCreated();

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-17',
            'type' => 'unavailable',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $this->postJson('/api/v1/appointments', [
            ...$payload,
            'starts_at' => '2026-06-17T11:00:00',
            'ends_at' => '2026-06-17T11:30:00',
        ])->assertCreated();
    }
}
