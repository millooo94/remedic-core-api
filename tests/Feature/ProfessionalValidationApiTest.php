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
}
