<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\Specialization;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfessionalValidationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    private function specializationPayload(): array
    {
        $specialization = Specialization::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            [
                'name' => 'Cardiologia',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        return [
            'area_name' => $specialization->name,
            'area_names' => [$specialization->name],
            'specialization_ids' => [$specialization->id],
        ];
    }

    #[Test]
    public function it_rejects_iban_values_that_become_invalid_after_normalization(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->givePermissionTo(AdminPermission::MANAGE_DOCTORS->value);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/professionals', [
            'subject_type' => 'individual',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'iban' => 'IT60 1234 5678',
            'is_active' => true,
        ] + $this->specializationPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
    }

    #[Test]
    public function it_requires_company_name_when_subject_type_is_company(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->givePermissionTo(AdminPermission::MANAGE_DOCTORS->value);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/professionals', [
            'subject_type' => 'company',
            'is_active' => true,
        ] + $this->specializationPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name']);
    }

    #[Test]
    public function it_rejects_company_name_when_subject_type_is_individual(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->givePermissionTo(AdminPermission::MANAGE_DOCTORS->value);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/professionals', [
            'subject_type' => 'individual',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'company_name' => 'Studio Rossi SRL',
            'is_active' => true,
        ] + $this->specializationPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name']);
    }
}
