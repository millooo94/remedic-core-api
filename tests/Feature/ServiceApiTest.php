<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePricingProfile;
use App\Models\Specialization;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $categoryCountBefore = ServiceCategory::query()->count();
        $specialization = $this->createSpecialization('Cardiologia', 'cardiologia');

        $cardioProfessional = Professional::factory()->create([
            'area_name' => 'Cardiologia',
        ]);

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'classification' => 'specialist_visit',
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
            ->assertJsonPath('category_id', null)
            ->assertJsonPath('category', null)
            ->assertJsonPath('professional_services.0.professional_id', $cardioProfessional->id)
            ->assertJsonPath('importo_prestazione', '125.00');

        $this->assertSame($categoryCountBefore, ServiceCategory::query()->count());
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
            'classification' => 'specialist_visit',
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
            'classification' => 'specialist_visit',
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
            'classification' => 'specialist_visit',
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
            'classification' => 'specialist_visit',
            'display_name' => 'Visita cardiologica',
            'importo_prestazione' => 125.50,
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $professional->id]],
        ])->assertCreated()->assertJsonPath('importo_prestazione', '125.50');

        $secondProfessional = Professional::factory()->create(['area_name' => 'Cardiologia']);
        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'classification' => 'specialist_visit',
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
            'classification' => 'diagnostic',
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
            'classification' => 'specialist_visit',
            'display_name' => 'Prima visita dermatologica',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $firstProfessional->id]],
        ])->assertCreated();

        $this->postJson('/api/v1/services', [
            'category_id' => $category->id,
            'classification' => 'specialist_visit',
            'display_name' => 'Prima visita dermatologica',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $secondProfessional->id]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'display_name',
            ]);
    }

    #[Test]
    public function it_requires_a_valid_master_classification_and_allows_it_to_change(): void
    {
        $specialization = $this->createSpecialization('Cardiologia', 'cardiologia');
        $professional = Professional::factory()->create(['area_name' => 'Cardiologia']);
        $payload = [
            'display_name' => 'Ecografia cardiaca',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $professional->id]],
        ];

        $this->postJson('/api/v1/services', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['classification']);
        $this->postJson('/api/v1/services', [...$payload, 'classification' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['classification']);

        $service = $this->postJson('/api/v1/services', [...$payload, 'classification' => 'diagnostic'])
            ->assertCreated()
            ->assertJsonPath('classification', 'diagnostic')
            ->json();

        $this->putJson('/api/v1/services/'.$service['id'], [...$payload, 'classification' => 'aesthetic_medicine', 'aliases' => [], 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['specialization_ids']);

        $aestheticSpecialization = $this->createSpecialization('Medicina estetica', Specialization::AESTHETIC_MEDICINE_SLUG);
        $aestheticProfessional = Professional::factory()->create(['area_name' => 'Medicina estetica']);
        $this->putJson('/api/v1/services/'.$service['id'], [
            'classification' => 'aesthetic_medicine',
            'display_name' => 'Ecografia cardiaca',
            'specialization_ids' => [$aestheticSpecialization->id],
            'professional_services' => [['professional_id' => $aestheticProfessional->id]],
            'aliases' => [],
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('classification', 'aesthetic_medicine');
    }

    #[Test]
    public function legacy_services_can_remain_unclassified_until_reviewed(): void
    {
        $service = Service::query()->create([
            'canonical_name' => 'Prestazione legacy',
            'display_name' => 'Prestazione legacy',
            'slug' => 'prestazione-legacy',
            'is_active' => true,
        ]);

        $this->assertNull($service->fresh()->classification);
    }

    #[Test]
    public function structured_pricing_accepts_all_recipients_for_any_service(): void
    {
        $service = Service::query()->create([
            'canonical_name' => 'Tariffario universale',
            'display_name' => 'Tariffario universale',
            'slug' => 'tariffario-universale',
            'classification' => 'aesthetic_medicine',
            'is_active' => true,
        ]);
        $profile = $this->postJson("/api/v1/services/{$service->id}/pricing/profiles", ['label' => 'Tariffario', 'is_active' => true])
            ->assertCreated()
            ->json('data.0');

        foreach (['unspecified', 'male', 'female'] as $recipient) {
            $this->postJson("/api/v1/services/{$service->id}/pricing/profiles/{$profile['id']}/items", [
                'label' => "Voce {$recipient}", 'kind' => 'variant', 'recipient' => $recipient,
                'price_amount' => '10.00', 'is_active' => true,
            ])->assertCreated();
        }

        $pricing = $this->getJson("/api/v1/services/{$service->id}/pricing")
            ->assertOk()
            ->json('data.0.items');
        $this->assertSame(['unspecified', 'male', 'female'], array_column($pricing, 'recipient'));

        $this->putJson("/api/v1/services/{$service->id}/pricing/profiles/{$profile['id']}/items/{$pricing[0]['id']}", [
            'label' => 'Voce aggiornata', 'kind' => 'variant', 'recipient' => 'female',
            'price_amount' => '12.00', 'is_active' => true,
        ])->assertOk()->assertJsonPath('data.0.items.0.recipient', 'female');

        $this->deleteJson("/api/v1/services/{$service->id}/pricing/profiles/{$profile['id']}/items/{$pricing[2]['id']}")
            ->assertOk()
            ->assertJsonCount(2, 'data.0.items');
    }

    #[Test]
    public function flat_pricing_items_share_an_automatic_technical_profile(): void
    {
        $service = Service::query()->create([
            'canonical_name' => 'Tariffario piano',
            'display_name' => 'Tariffario piano',
            'slug' => 'tariffario-piano',
            'classification' => 'aesthetic_medicine',
            'is_active' => true,
        ]);

        foreach (['Viso', 'Gambe complete'] as $label) {
            $this->postJson("/api/v1/services/{$service->id}/pricing/items", [
                'label' => $label,
                'kind' => 'variant',
                'recipient' => 'female',
                'price_amount' => '50.00',
                'business_note' => null,
                'is_active' => true,
            ])->assertCreated();
        }

        $this->assertDatabaseCount('service_pricing_profiles', 1)
            ->assertDatabaseCount('service_pricing_items', 2);
        $this->getJson("/api/v1/services/{$service->id}/pricing")
            ->assertOk()
            ->assertJsonCount(2, 'data.0.items');
    }

    #[Test]
    public function structured_pricing_is_restricted_to_aesthetic_medicine_and_blocks_an_unsafe_reclassification(): void
    {
        $specialization = $this->createSpecialization('Dermatologia', 'dermatologia');
        $professional = Professional::factory()->create(['area_name' => 'Dermatologia']);
        $aesthetic = Service::query()->create([
            'canonical_name' => 'Biostimolazione',
            'display_name' => 'Biostimolazione',
            'slug' => 'biostimolazione',
            'classification' => 'aesthetic_medicine',
            'is_active' => true,
        ]);
        $aesthetic->specializations()->attach($specialization->id, ['is_primary' => true, 'sort_order' => 0]);
        $aesthetic->professionalServices()->create(['professional_id' => $professional->id, 'is_active' => true]);

        $itemPayload = [
            'label' => 'Viso',
            'kind' => 'variant',
            'recipient' => 'female',
            'price_amount' => '50.00',
            'business_note' => null,
            'is_active' => true,
        ];
        $this->postJson("/api/v1/services/{$aesthetic->id}/pricing/items", $itemPayload)->assertCreated();

        foreach (['specialist_visit', 'diagnostic'] as $classification) {
            $service = Service::query()->create([
                'canonical_name' => "Servizio {$classification}",
                'display_name' => "Servizio {$classification}",
                'slug' => "servizio-{$classification}",
                'classification' => $classification,
                'is_active' => true,
            ]);
            $this->postJson("/api/v1/services/{$service->id}/pricing/items", $itemPayload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['classification']);
        }

        $updatePayload = [
            'classification' => 'diagnostic',
            'display_name' => 'Biostimolazione',
            'specialization_ids' => [$specialization->id],
            'professional_services' => [['professional_id' => $professional->id]],
            'aliases' => [],
            'is_active' => true,
        ];
        $this->putJson("/api/v1/services/{$aesthetic->id}", $updatePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['classification']);
        $this->assertDatabaseCount('service_pricing_items', 1);

        $profile = $aesthetic->pricingProfiles()->firstOrFail();
        $item = $profile->items()->firstOrFail();
        $this->deleteJson("/api/v1/services/{$aesthetic->id}/pricing/profiles/{$profile->id}/items/{$item->id}")->assertOk();
        $this->putJson("/api/v1/services/{$aesthetic->id}", $updatePayload)
            ->assertOk()
            ->assertJsonPath('classification', 'diagnostic');
    }

    #[Test]
    public function structured_pricing_supports_free_items_optional_areas_images_and_independent_ordering(): void
    {
        Storage::fake('public');
        $service = Service::query()->create([
            'canonical_name' => 'Epilazione laser',
            'display_name' => 'Epilazione laser',
            'slug' => 'epilazione-laser',
            'classification' => 'aesthetic_medicine',
            'is_active' => true,
        ]);
        $item = fn (string $label, string $recipient = 'unspecified') => [
            'label' => $label, 'kind' => 'variant', 'recipient' => $recipient,
            'price_amount' => '30.00', 'business_note' => null, 'is_active' => true,
        ];

        $this->postJson("/api/v1/services/{$service->id}/pricing/items", $item('Total body', 'female'))->assertCreated();
        $this->postJson("/api/v1/services/{$service->id}/pricing/items", $item('Pacchetto 5 sedute'))->assertCreated();
        $braccia = $this->postJson("/api/v1/services/{$service->id}/pricing/profiles", ['label' => 'Braccia', 'is_active' => true])
            ->assertCreated()->json('data.1');
        $gambe = $this->postJson("/api/v1/services/{$service->id}/pricing/profiles", ['label' => 'Gambe', 'is_active' => true])
            ->assertCreated()->json('data.2');
        $this->postJson("/api/v1/services/{$service->id}/pricing/profiles/{$braccia['id']}/items", $item('Ascelle', 'male'))->assertCreated();
        $this->postJson("/api/v1/services/{$service->id}/pricing/profiles/{$gambe['id']}/items", $item('Mezza gamba', 'female'))->assertCreated();
        $this->postJson("/api/v1/services/{$service->id}/pricing/profiles/{$braccia['id']}/image", ['image' => UploadedFile::fake()->image('braccia.jpg')])
            ->assertOk();

        $this->postJson("/api/v1/services/{$service->id}/pricing/profiles/reorder", ['ids' => [$gambe['id'], $braccia['id']]])->assertOk();
        $payload = $this->getJson("/api/v1/services/{$service->id}/pricing")->assertOk()->json('data');
        $this->assertTrue($payload[0]['is_ungrouped']);
        $this->assertCount(2, $payload[0]['items']);
        $this->postJson("/api/v1/services/{$service->id}/pricing/items/reorder", ['ids' => array_reverse(array_column($payload[0]['items'], 'id'))])->assertOk();
        $payload = $this->getJson("/api/v1/services/{$service->id}/pricing")->assertOk()->json('data');
        $this->assertSame(['Pacchetto 5 sedute', 'Total body'], array_column($payload[0]['items'], 'label'));
        $this->assertSame('Gambe', $payload[1]['label']);
        $this->assertSame('Braccia', $payload[2]['label']);
        $this->assertSame('male', $payload[2]['items'][0]['recipient']);
        $this->assertNotNull($payload[2]['image_path']);

        $this->deleteJson("/api/v1/services/{$service->id}/pricing/profiles/{$braccia['id']}/image")->assertOk();
        $this->assertNull($this->getJson("/api/v1/services/{$service->id}/pricing")->json('data.2.image_path'));
    }

    #[Test]
    public function empty_areas_do_not_create_free_pricing_or_change_legacy_profiles(): void
    {
        $service = Service::query()->create([
            'canonical_name' => 'Biostimolazione',
            'display_name' => 'Biostimolazione',
            'slug' => 'biostimolazione-aree',
            'classification' => 'aesthetic_medicine',
            'is_active' => true,
        ]);
        $legacy = ServicePricingProfile::query()->create(['service_id' => $service->id, 'label' => 'Viso e collo', 'is_active' => true]);

        $this->postJson("/api/v1/services/{$service->id}/pricing/profiles", ['label' => 'Gambe', 'is_active' => true])->assertCreated();
        $payload = $this->getJson("/api/v1/services/{$service->id}/pricing")->assertOk()->json('data');

        $this->assertCount(2, $payload);
        $this->assertFalse($payload[0]['is_ungrouped']);
        $this->assertSame($legacy->id, $payload[0]['id']);
        $this->assertSame([], $payload[0]['items']);
        $this->assertDatabaseCount('service_pricing_items', 0);
    }
}
