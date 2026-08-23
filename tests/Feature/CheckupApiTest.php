<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\Specialization;
use App\Models\User;
use App\Services\CheckupCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    #[Test]
    public function services_remain_atomic_and_their_endpoint_does_not_contain_checkups(): void
    {
        $service = $this->createService('Visita atomica');

        $this->postJson('/api/v1/checkups', $this->payload([$service->id], [
            'display_name' => 'Check-up separato',
        ]))->assertCreated();

        $this->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.kind', 'service')
            ->assertJsonMissing(['display_name' => 'Check-up separato']);
    }

    #[Test]
    public function it_creates_a_checkup_with_own_values_and_ordered_services(): void
    {
        $first = $this->createService('Visita cardiologica', 100, 30);
        $second = $this->createService('ECG', 40, 20);
        $third = $this->createService('Ecocardiogramma', 80, 40);

        $this->postJson('/api/v1/checkups', $this->payload([$second->id, $first->id, $third->id], [
            'price_amount' => 180,
            'indicative_duration_minutes' => 75,
        ]))->assertCreated()
            ->assertJsonPath('kind', 'checkup')
            ->assertJsonPath('price_amount', '180.00')
            ->assertJsonPath('indicative_duration_minutes', 75)
            ->assertJsonPath('items.0.service_id', $second->id)
            ->assertJsonPath('items.0.sort_order', 0)
            ->assertJsonPath('items.1.service_id', $first->id)
            ->assertJsonPath('items.1.sort_order', 1)
            ->assertJsonPath('items.2.service_id', $third->id)
            ->assertJsonPath('items.2.sort_order', 2)
            ->assertJsonPath('items_count', 3)
            ->assertJsonPath('is_operationally_available', true);

        $this->assertDatabaseHas('checkups', [
            'price_amount' => 180,
            'indicative_duration_minutes' => 75,
        ]);
    }

    #[Test]
    public function it_updates_and_normalizes_item_order(): void
    {
        $first = $this->createService('Prima prestazione');
        $second = $this->createService('Seconda prestazione');
        $third = $this->createService('Terza prestazione');
        $checkupId = $this->postJson('/api/v1/checkups', $this->payload([$first->id, $second->id]))
            ->assertCreated()
            ->json('id');

        $this->putJson("/api/v1/checkups/{$checkupId}", $this->payload([$third->id, $second->id, $first->id], [
            'display_name' => 'Check-up riordinato',
            'price_amount' => 199,
        ]))->assertOk()
            ->assertJsonPath('display_name', 'Check-up riordinato')
            ->assertJsonPath('items.0.service_id', $third->id)
            ->assertJsonPath('items.1.service_id', $second->id)
            ->assertJsonPath('items.2.service_id', $first->id);

        $this->assertDatabaseHas('checkup_services', [
            'checkup_id' => $checkupId,
            'service_id' => $third->id,
            'sort_order' => 0,
        ]);
    }

    #[Test]
    public function it_rejects_empty_duplicate_and_missing_services(): void
    {
        $service = $this->createService('Prestazione valida');

        $this->postJson('/api/v1/checkups', $this->payload([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->postJson('/api/v1/checkups', $this->payload([$service->id, $service->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.service_id']);

        $this->postJson('/api/v1/checkups', $this->payload([999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.service_id']);
    }

    #[Test]
    public function it_explicitly_prohibits_professionals_and_specializations(): void
    {
        $service = $this->createService('Prestazione protetta');
        $forbiddenPayloads = [
            ['professional_id' => 1, 'error' => 'professional_id'],
            ['professional_ids' => [1], 'error' => 'professional_ids'],
            ['specialization_id' => 1, 'error' => 'specialization_id'],
            ['specialization_ids' => [1], 'error' => 'specialization_ids'],
        ];

        foreach ($forbiddenPayloads as $case) {
            $payload = $this->payload([$service->id]);
            $payload[$case['error']] = $case[$case['error']];

            $this->postJson('/api/v1/checkups', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$case['error']]);
        }

        $payload = $this->payload([$service->id]);
        $payload['items'][0]['professional_id'] = 1;
        $this->postJson('/api/v1/checkups', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.professional_id']);
    }

    #[Test]
    public function an_active_checkup_rejects_inactive_items_but_an_inactive_draft_preserves_them(): void
    {
        $inactiveService = $this->createService('Prestazione inattiva', 60, 20, false);

        $this->postJson('/api/v1/checkups', $this->payload([$inactiveService->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $checkupId = $this->postJson('/api/v1/checkups', $this->payload([$inactiveService->id], [
            'display_name' => 'Bozza Check-up',
            'is_active' => false,
        ]))->assertCreated()
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('is_operationally_available', false)
            ->assertJsonPath('has_inactive_items', true)
            ->json('id');

        $this->putJson("/api/v1/checkups/{$checkupId}", $this->payload([$inactiveService->id], [
            'display_name' => 'Bozza Check-up',
            'is_active' => true,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);
    }

    #[Test]
    public function a_service_deactivated_later_makes_the_checkup_operationally_unavailable(): void
    {
        $service = $this->createService('Prestazione poi disattivata');
        $checkupId = $this->postJson('/api/v1/checkups', $this->payload([$service->id]))
            ->assertCreated()
            ->json('id');

        $service->update(['is_active' => false]);

        $this->getJson("/api/v1/checkups/{$checkupId}")
            ->assertOk()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('is_operationally_available', false)
            ->assertJsonPath('has_inactive_items', true)
            ->assertJsonPath('inactive_items_count', 1);
    }

    #[Test]
    public function a_referenced_service_cannot_be_deleted_and_returns_an_application_conflict(): void
    {
        $service = $this->createService('Prestazione referenziata');
        $this->postJson('/api/v1/checkups', $this->payload([$service->id]))->assertCreated();

        $this->deleteJson("/api/v1/services/{$service->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'La prestazione non puo essere eliminata perche e inclusa in uno o piu Check-up. Disattivala oppure rimuovila prima dai Check-up.');

        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertDatabaseHas('checkup_services', ['service_id' => $service->id]);
    }

    #[Test]
    public function deleting_a_checkup_is_soft_and_does_not_change_its_services_or_composition(): void
    {
        $service = $this->createService('Prestazione conservata');
        $checkupId = $this->postJson('/api/v1/checkups', $this->payload([$service->id]))
            ->assertCreated()
            ->json('id');

        $this->deleteJson("/api/v1/checkups/{$checkupId}")->assertNoContent();

        $this->assertSoftDeleted('checkups', ['id' => $checkupId, 'is_active' => false]);
        $this->assertDatabaseHas('checkup_services', [
            'checkup_id' => $checkupId,
            'service_id' => $service->id,
        ]);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'is_active' => true]);
        $this->getJson("/api/v1/checkups/{$checkupId}")->assertNotFound();
    }

    #[Test]
    public function it_derives_ordered_areas_and_unique_active_professionals(): void
    {
        $cardiology = Specialization::query()->create([
            'name' => 'Cardiologia',
            'slug' => 'cardiologia-checkup',
            'is_active' => true,
        ]);
        $diagnostics = Specialization::query()->create([
            'name' => 'Diagnostica',
            'slug' => 'diagnostica-checkup',
            'is_active' => true,
        ]);
        $first = $this->createService('Visita derivata');
        $second = $this->createService('Esame derivato');
        $first->specializations()->attach($cardiology->id, ['is_primary' => true, 'sort_order' => 0]);
        $second->specializations()->attach([
            $diagnostics->id => ['is_primary' => true, 'sort_order' => 0],
            $cardiology->id => ['is_primary' => false, 'sort_order' => 1],
        ]);
        $sharedProfessional = Professional::factory()->create(['is_active' => true]);
        $inactiveProfessional = Professional::factory()->create(['is_active' => false]);
        foreach ([$first, $second] as $service) {
            ProfessionalService::query()->create([
                'professional_id' => $sharedProfessional->id,
                'service_id' => $service->id,
                'is_active' => true,
            ]);
        }
        ProfessionalService::query()->create([
            'professional_id' => $inactiveProfessional->id,
            'service_id' => $second->id,
            'is_active' => true,
        ]);

        $checkupId = $this->postJson('/api/v1/checkups', $this->payload([$first->id, $second->id]))
            ->assertCreated()
            ->json('id');

        $this->getJson("/api/v1/checkups/{$checkupId}")
            ->assertOk()
            ->assertJsonPath('areas.0.name', 'Cardiologia')
            ->assertJsonPath('areas.1.name', 'Diagnostica')
            ->assertJsonPath('professionals_count', 1)
            ->assertJsonCount(1, 'professionals')
            ->assertJsonPath('professionals.0.id', $sharedProfessional->id)
            ->assertJsonPath('items.0.professionals_count', 1);
    }

    #[Test]
    public function list_filters_use_derived_area_professional_and_status(): void
    {
        $specialization = Specialization::query()->create([
            'name' => 'Neurologia',
            'slug' => 'neurologia-checkup',
            'is_active' => true,
        ]);
        $service = $this->createService('Visita neurologica');
        $service->specializations()->attach($specialization->id, ['is_primary' => true, 'sort_order' => 0]);
        $professional = Professional::factory()->create(['is_active' => true]);
        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);
        $this->postJson('/api/v1/checkups', $this->payload([$service->id], [
            'display_name' => 'Percorso neurologico',
        ]))->assertCreated();

        $this->getJson('/api/v1/checkups?q=Percorso&is_active=1&specialization_name=Neurologia&professional_id='.$professional->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.display_name', 'Percorso neurologico');

        $this->getJson('/api/v1/checkups?specialization_name=Cardiologia')
            ->assertOk()
            ->assertJsonCount(0);
    }

    #[Test]
    public function catalog_service_rolls_back_the_master_when_item_sync_fails(): void
    {
        try {
            app(CheckupCatalogService::class)->create($this->payload([999999], [
                'display_name' => 'Check-up da annullare',
            ]));
            $this->fail('La sincronizzazione avrebbe dovuto fallire.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseMissing('checkups', ['display_name' => 'Check-up da annullare']);
    }

    #[Test]
    public function checkup_routes_use_the_same_admin_access_policy_as_services(): void
    {
        $service = $this->createService('Prestazione autorizzazione');
        auth()->guard()->forgetUser();

        $this->getJson('/api/v1/checkups')->assertUnauthorized();
        $this->postJson('/api/v1/checkups', $this->payload([$service->id]))->assertUnauthorized();
    }

    private function createService(
        string $name,
        int $price = 100,
        int $duration = 30,
        bool $active = true,
    ): Service {
        return Service::factory()->create([
            'category_id' => null,
            'display_name' => $name,
            'canonical_name' => $name,
            'importo_prestazione' => $price,
            'default_duration_minutes' => $duration,
            'is_active' => $active,
        ]);
    }

    private function payload(array $serviceIds, array $overrides = []): array
    {
        return [
            'display_name' => 'Check-up cardiologico',
            'price_amount' => 180,
            'indicative_duration_minutes' => 90,
            'is_active' => true,
            'organizational_notes' => null,
            'items' => array_map(fn (int $serviceId): array => ['service_id' => $serviceId], $serviceIds),
            ...$overrides,
        ];
    }
}
