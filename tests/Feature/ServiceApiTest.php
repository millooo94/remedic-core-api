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
            'importo_prestazione' => 125,
            'default_duration_minutes' => 30,
            'professional_services' => [
                [
                    'professional_id' => $cardioProfessional->id,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('category.name', 'Cardiologia')
            ->assertJsonPath('professional_services.0.professional_id', $cardioProfessional->id)
            ->assertJsonPath('importo_prestazione', '125.00');
    }

    #[Test]
    public function it_creates_service_with_multiple_professionals_from_the_same_area(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ServiceCategory::factory()->create([
            'name' => 'Dermatologia',
            'slug' => 'dermatologia',
        ]);

        $professionals = Professional::factory()->count(3)->create([
            'area_name' => 'Dermatologia',
        ]);

        $response = $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Prima visita dermatologica',
            'importo_prestazione' => 120,
            'professional_services' => $professionals
                ->map(fn (Professional $professional) => ['professional_id' => $professional->id])
                ->values()
                ->all(),
        ])->assertCreated();

        $this->assertCount(3, $response->json('professional_services'));
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

    #[Test]
    public function it_rejects_invalid_optional_importo_values(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ServiceCategory::factory()->create([
            'name' => 'Cardiologia',
            'slug' => 'cardiologia',
        ]);

        $professional = Professional::factory()->create([
            'area_name' => 'Cardiologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'importo_prestazione' => -10,
            'professional_services' => [['professional_id' => $professional->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'importo_prestazione',
            ]);
    }

    #[Test]
    public function it_rejects_decimal_importo_values(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ServiceCategory::factory()->create([
            'name' => 'Cardiologia',
            'slug' => 'cardiologia',
        ]);

        $professional = Professional::factory()->create([
            'area_name' => 'Cardiologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'importo_prestazione' => 125.50,
            'professional_services' => [['professional_id' => $professional->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'importo_prestazione',
            ]);
    }

    #[Test]
    public function it_rejects_duplicate_service_name_within_the_same_area(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $category = ServiceCategory::factory()->create([
            'name' => 'Dermatologia',
            'slug' => 'dermatologia',
        ]);

        $firstProfessional = Professional::factory()->create([
            'area_name' => 'Dermatologia',
        ]);
        $secondProfessional = Professional::factory()->create([
            'area_name' => 'Dermatologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Prima visita dermatologica',
            'professional_services' => [['professional_id' => $firstProfessional->id]],
        ])->assertCreated();

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Prima visita dermatologica',
            'professional_services' => [['professional_id' => $secondProfessional->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'display_name',
            ]);
    }
}
