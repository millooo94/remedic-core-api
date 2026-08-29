<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\PerformanceRecord;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PatientApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_patient_and_returns_marketing_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $response = $this->postJson('/api/v1/patients', [
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'sex' => 'male',
            'year_of_birth' => 1978,
            'phone' => '3331234567',
            'email' => 'mario.rossi@example.test',
            'residence_province' => 'Catania',
            'contactable_sms' => true,
            'contactable_whatsapp' => true,
            'contactable_email' => true,
            'excluded_from_campaigns' => false,
            'notes' => 'Paziente marketing',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('first_name', 'Mario')
            ->assertJsonPath('last_name', 'Rossi')
            ->assertJsonPath('full_name', 'Mario Rossi')
            ->assertJsonPath('sex', 'male')
            ->assertJsonPath('residence_province', 'Catania')
            ->assertJsonPath('available_channels.sms', true)
            ->assertJsonPath('available_channels.whatsapp', true)
            ->assertJsonPath('available_channels.email', true)
            ->assertJsonPath('performances_count', 0)
            ->assertJsonPath('last_visit_at', null);

        $this->assertDatabaseHas('patients', [
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Rossi Mario',
            'sex' => 'male',
        ]);
    }

    #[Test]
    public function it_defaults_new_patient_contact_channels_without_changing_existing_patients(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/patients', [
            'first_name' => 'Default',
            'last_name' => 'Canali',
            'excluded_from_campaigns' => false,
        ])
            ->assertOk()
            ->assertJsonPath('contactable_whatsapp', true)
            ->assertJsonPath('contactable_sms', true)
            ->assertJsonPath('contactable_email', false);

        $existing = Patient::factory()->create([
            'contactable_whatsapp' => false,
            'contactable_sms' => false,
            'contactable_email' => true,
        ]);

        $this->putJson("/api/v1/patients/{$existing->id}", [
            'first_name' => $existing->first_name,
            'last_name' => $existing->last_name,
            'excluded_from_campaigns' => $existing->excluded_from_campaigns,
        ])->assertOk();

        $this->assertDatabaseHas('patients', [
            'id' => $existing->id,
            'contactable_whatsapp' => false,
            'contactable_sms' => false,
            'contactable_email' => true,
        ]);
    }

    #[Test]
    public function it_allows_updating_patient_sex_back_to_null(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $patient = Patient::factory()->create([
            'first_name' => 'Giulia',
            'last_name' => 'Neri',
            'full_name' => 'Neri Giulia',
            'sex' => 'female',
        ]);

        $this->putJson("/api/v1/patients/{$patient->id}", [
            'first_name' => 'Giulia',
            'last_name' => 'Neri',
            'sex' => null,
            'birth_date' => null,
            'phone' => null,
            'email' => null,
            'residence_address' => null,
            'residence_city' => null,
            'residence_province' => null,
            'residence_zip' => null,
            'contactable_sms' => true,
            'contactable_whatsapp' => false,
            'contactable_email' => true,
            'excluded_from_campaigns' => false,
            'notes' => null,
        ])
            ->assertOk()
            ->assertJsonPath('sex', null);

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'sex' => null,
        ]);
    }

    #[Test]
    public function it_lists_patients_with_marketing_filters(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $patient = Patient::factory()->create([
            'first_name' => 'Lucia',
            'last_name' => 'Bianchi',
            'full_name' => 'Bianchi Lucia',
            'contactable_sms' => true,
            'contactable_whatsapp' => true,
            'contactable_email' => true,
            'excluded_from_campaigns' => false,
        ]);
        $professional = Professional::factory()->create([
            'first_name' => 'Giuseppe',
            'last_name' => 'Verdi',
            'full_name' => 'Verdi Giuseppe',
            'area_name' => 'Dermatologia',
        ]);

        PerformanceRecord::query()->create([
            'performed_at' => '2026-03-11',
            'patient_id' => $patient->id,
            'professional_id' => $professional->id,
            'professional_name_snapshot' => $professional->full_name,
            'category_name_snapshot' => 'Dermatologia',
            'service_id' => null,
            'service_name_snapshot' => 'Visita dermatologica',
            'quantity' => 1,
            'unit_amount' => 100,
            'total_amount' => 100,
            'direct_cost' => 0,
            'calculation_mode' => 'percentage',
            'split_mode' => 'standard',
            'percentage_value' => 70,
            'fixed_amount' => null,
            'professional_amount' => 70,
            'center_amount' => 30,
            'payment_method' => 'cash',
            'payment_status' => 'da_pagare',
            'is_invoiced' => false,
            'is_black' => false,
        ])->patients()->sync([
            $patient->id => ['sort_order' => 0],
        ]);

        $this->getJson('/api/v1/patients?contactable_sms=1')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Lucia Bianchi')
            ->assertJsonPath('data.0.performances_count', 1)
            ->assertJsonPath('data.0.visited_specializations.0', 'Dermatologia');
    }

    #[Test]
    public function it_finds_patients_with_name_surname_or_surname_name_queries(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Patient::factory()->create([
            'first_name' => 'Anna Maria',
            'last_name' => 'Verdi',
            'full_name' => 'Verdi Anna Maria',
        ]);

        $this->getJson('/api/v1/patients?q=Anna%20Maria%20Verdi')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Anna Maria Verdi');

        $this->getJson('/api/v1/patients?q=Verdi%20Anna')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', 'Anna Maria Verdi');
    }
}
