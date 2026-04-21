<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_service_with_professionals_from_selected_area(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ServiceCategory::factory()->create([
            'name' => 'Cardiologia',
            'slug' => 'cardiologia',
        ]);

        $cardioProfessional = Professional::factory()->create([
            'area_name' => 'Cardiologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'default_duration_minutes' => 30,
            'professional_services' => [
                ['professional_id' => $cardioProfessional->id],
            ],
        ])->assertCreated()
            ->assertJsonPath('category.name', 'Cardiologia')
            ->assertJsonPath('professional_services.0.professional_id', $cardioProfessional->id);
    }

    #[Test]
    public function it_rejects_professionals_outside_selected_area(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $cardioCategory = ServiceCategory::factory()->create([
            'name' => 'Cardiologia',
            'slug' => 'cardiologia',
        ]);

        $otherAreaProfessional = Professional::factory()->create([
            'area_name' => 'Dermatologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $cardioCategory->id,
            'display_name' => 'Visita cardiologica',
            'professional_services' => [
                ['professional_id' => $otherAreaProfessional->id],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['professional_services']);
    }
}

