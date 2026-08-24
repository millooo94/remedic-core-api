<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Specialization;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    private function createSpecialization(string $name, string $slug): Specialization
    {
        return Specialization::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_active' => true, 'sort_order' => 1],
        );
    }

    #[Test]
    public function it_creates_service_with_professionals_from_selected_area(): void
    {
        $category = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'sort_order' => 1],
        );
        $specialization = $this->createSpecialization('Cardiologia', 'cardiologia');

        $cardioProfessional = Professional::factory()->create([
            'area_name' => 'Cardiologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'importo_prestazione' => 125,
            'default_duration_minutes' => 30,
            'specialization_ids' => [$specialization->id],
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
        $category = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'dermatologia'],
            ['name' => 'Dermatologia', 'is_active' => true, 'sort_order' => 1],
        );
        $specialization = $this->createSpecialization('Dermatologia', 'dermatologia');

        $professionals = Professional::factory()->count(3)->create([
            'area_name' => 'Dermatologia',
        ]);

        $response = $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Prima visita dermatologica',
            'importo_prestazione' => 120,
            'specialization_ids' => [$specialization->id],
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
        $cardioCategory = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'sort_order' => 1],
        );
        $specialization = $this->createSpecialization('Cardiologia', 'cardiologia');

        $otherAreaProfessional = Professional::factory()->create([
            'area_name' => 'Dermatologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $cardioCategory->id,
            'display_name' => 'Visita cardiologica',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [
                ['professional_id' => $otherAreaProfessional->id],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['professional_services']);
    }

    #[Test]
    public function it_rejects_invalid_optional_importo_values(): void
    {
        $category = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'sort_order' => 1],
        );
        $specialization = $this->createSpecialization('Cardiologia', 'cardiologia');

        $professional = Professional::factory()->create([
            'area_name' => 'Cardiologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'importo_prestazione' => -10,
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $professional->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'importo_prestazione',
            ]);
    }

    #[Test]
    public function it_accepts_decimal_importo_values_with_dot_or_comma(): void
    {
        $category = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'sort_order' => 1],
        );
        $specialization = $this->createSpecialization('Cardiologia', 'cardiologia');

        $professional = Professional::factory()->create([
            'area_name' => 'Cardiologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'importo_prestazione' => 125.50,
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $professional->id]],
        ])->assertCreated()->assertJsonPath('importo_prestazione', '125.50');

        $secondProfessional = Professional::factory()->create(['area_name' => 'Cardiologia']);
        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'ECG dinamico',
            'importo_prestazione' => '99,90',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $secondProfessional->id]],
        ])->assertCreated()->assertJsonPath('importo_prestazione', '99.90');
    }

    #[Test]
    public function management_update_does_not_wipe_legacy_description_or_regenerate_master_slug(): void
    {
        $category = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'sort_order' => 1],
        );
        $specialization = $this->createSpecialization('Cardiologia', 'cardiologia');
        $professional = Professional::factory()->create(['area_name' => 'Cardiologia']);
        $service = Service::query()->create([
            'category_id' => $category->id,
            'canonical_name' => 'Visita legacy',
            'display_name' => 'Visita legacy',
            'slug' => 'slug-master-immutabile',
            'description' => 'Descrizione Web legacy da conservare.',
            'is_active' => true,
        ]);
        $service->specializations()->attach($specialization->id, ['is_primary' => true, 'sort_order' => 0]);
        $service->professionalServices()->create(['professional_id' => $professional->id, 'is_active' => true]);

        $this->putJson("/api/v1/services/{$service->id}", [
            'category_id' => $category->id,
            'display_name' => 'Visita aggiornata',
            'importo_prestazione' => '120,50',
            'default_duration_minutes' => 30,
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $professional->id]],
            'is_active' => true,
            'aliases' => [],
        ])->assertOk()->assertJsonPath('slug', 'slug-master-immutabile');

        $service->refresh();
        $this->assertSame('Descrizione Web legacy da conservare.', $service->description);
        $this->assertSame('120.50', $service->importo_prestazione);
    }

    #[Test]
    public function it_rejects_duplicate_service_name_within_the_same_area(): void
    {
        $category = ServiceCategory::query()->firstOrCreate(
            ['slug' => 'dermatologia'],
            ['name' => 'Dermatologia', 'is_active' => true, 'sort_order' => 1],
        );
        $specialization = $this->createSpecialization('Dermatologia', 'dermatologia');

        $firstProfessional = Professional::factory()->create([
            'area_name' => 'Dermatologia',
        ]);
        $secondProfessional = Professional::factory()->create([
            'area_name' => 'Dermatologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Prima visita dermatologica',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $firstProfessional->id]],
        ])->assertCreated();

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'display_name' => 'Prima visita dermatologica',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $secondProfessional->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'display_name',
            ]);
    }
}
