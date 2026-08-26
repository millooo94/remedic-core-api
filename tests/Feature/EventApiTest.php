<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Checkup;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function it_validates_and_derives_event_lifecycle_location_and_registration(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/v1/events', $this->payload(['end_at' => now()->subHour()->toIso8601String()]))->assertUnprocessable();
        $this->postJson('/api/v1/events', $this->payload(['operational_status' => 'confirmed', 'location_type' => 'online']))->assertUnprocessable()->assertJsonValidationErrors('online_url');
        $this->postJson('/api/v1/events', $this->payload(['registration_required' => false, 'registration_mode' => 'contact']))->assertUnprocessable();
        $response = $this->postJson('/api/v1/events', $this->payload(['operational_status' => 'confirmed', 'location_type' => 'online', 'online_url' => 'https://meet.example.test/event', 'registration_required' => true, 'registration_mode' => 'external_url', 'external_registration_url' => 'https://register.example.test']))->assertCreated();
        $response->assertJsonPath('temporal_status', 'upcoming')->assertJsonPath('is_effectively_available', true)->assertJsonPath('is_registration_open', true);
    }

    #[Test]
    public function it_preserves_typed_relations_and_archives_and_restores(): void
    {
        $this->actingAsAdmin();
        $service = Service::query()->create(['display_name' => 'Visita', 'canonical_name' => 'Visita', 'slug' => 'visita', 'is_active' => true]);
        $checkup = Checkup::query()->create(['display_name' => 'Check', 'price_amount' => 100, 'indicative_duration_minutes' => 30, 'is_active' => true]);
        $promotion = Promotion::query()->create(['name' => 'Promo', 'service_id' => $service->id, 'promotional_price' => 50, 'start_at' => now(), 'end_at' => now()->addDay(), 'validity_basis' => 'booking_date', 'is_active' => false]);
        $event = $this->postJson('/api/v1/events', $this->payload(['service_ids' => [$service->id], 'checkup_ids' => [$checkup->id], 'promotion_ids' => [$promotion->id]]))->assertCreated()->json();
        $this->assertSame('Visita', $event['relations']['services'][0]['name']);
        $this->deleteJson('/api/v1/events/'.$event['id'])->assertNoContent();
        $this->getJson('/api/v1/events')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/events/'.$event['id'].'/restore')->assertOk()->assertJsonPath('is_archived', false);
    }

    private function payload(array $overrides = []): array
    {
        return [...['name' => 'Open Day', 'event_type' => 'open_day', 'operational_status' => 'planned', 'start_at' => now()->addDay()->toIso8601String(), 'end_at' => now()->addDays(2)->toIso8601String(), 'location_type' => 'remedic', 'external_venue_name' => null, 'external_venue_address' => null, 'online_url' => null, 'registration_required' => false, 'registration_deadline' => null, 'registration_mode' => 'none', 'external_registration_url' => null, 'capacity' => null, 'participation_price' => 0, 'cancellation_reason' => null, 'internal_notes' => 'Solo gestione', 'professional_ids' => [], 'specialization_ids' => [], 'service_ids' => [], 'checkup_ids' => [], 'promotion_ids' => []], ...$overrides];
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
