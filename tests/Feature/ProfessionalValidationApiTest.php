<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfessionalValidationApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_iban_values_that_become_invalid_after_normalization(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/professionals', [
            'subject_type' => 'individual',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'area_name' => 'Cardiologia',
            'area_names' => ['Cardiologia'],
            'iban' => 'IT60 1234 5678',
            'is_active' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
    }

    #[Test]
    public function it_requires_company_name_when_subject_type_is_company(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/professionals', [
            'subject_type' => 'company',
            'area_name' => 'Cardiologia',
            'area_names' => ['Cardiologia'],
            'is_active' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name']);
    }

    #[Test]
    public function it_rejects_company_name_when_subject_type_is_individual(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/professionals', [
            'subject_type' => 'individual',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'company_name' => 'Studio Rossi SRL',
            'area_name' => 'Cardiologia',
            'area_names' => ['Cardiologia'],
            'is_active' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name']);
    }
}
