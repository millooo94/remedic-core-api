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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PromotionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function creates_promotions_with_xor_dates_prices_and_runtime_derivations(): void
    {
        $this->actingAsAdmin();
        $service = $this->service('Laser', 100);
        $checkup = $this->checkup('Cardiologico', 200);
        $response = $this->postJson('/api/v1/promotions', $this->payload(['service_id' => $service->id, 'promotional_price' => 80, 'is_active' => true]));
        $response->assertCreated()->assertJsonPath('target_name', 'Laser')->assertJsonPath('standard_price', 100)->assertJsonPath('saving_amount', 20)->assertJsonPath('discount_percentage', 20);
        $this->postJson('/api/v1/promotions', $this->payload(['checkup_id' => $checkup->id, 'service_id' => null, 'promotional_price' => 150]))->assertCreated()->assertJsonPath('target_type', 'checkup')->assertJsonPath('standard_price', 200)->assertJsonPath('is_active', false);
        $this->postJson('/api/v1/promotions', $this->payload(['service_id' => $service->id, 'checkup_id' => $checkup->id]))->assertUnprocessable();
        $this->postJson('/api/v1/promotions', $this->payload(['promotional_price' => -1]))->assertUnprocessable();
        $this->postJson('/api/v1/promotions', $this->payload(['service_id' => null, 'start_at' => now()->addDay()->toIso8601String(), 'end_at' => now()->addHour()->toIso8601String()]))->assertUnprocessable();
        $service->update(['importo_prestazione' => 120]);
        $this->getJson('/api/v1/promotions/1')->assertJsonPath('standard_price', 120)->assertJsonPath('saving_amount', 40);
    }

    #[Test]
    public function lifecycle_overlap_archive_restore_and_lookups_follow_operational_rules(): void
    {
        $this->actingAsAdmin();
        $service = $this->service('Visita', 100);
        $checkup = $this->checkup('Check', 180);
        $active = $this->create($service, now()->subDay(), now()->addDay(), true);
        $this->postJson('/api/v1/promotions', $this->payload(['service_id' => $service->id, 'start_at' => now(), 'end_at' => now()->addDays(2)->toIso8601String(), 'is_active' => true]))->assertUnprocessable()->assertJsonValidationErrors('start_at');
        $this->deleteJson('/api/v1/promotions/'.$active->id)->assertNoContent();
        $this->postJson('/api/v1/promotions', $this->payload(['service_id' => $service->id, 'start_at' => now()->subHour()->toIso8601String(), 'end_at' => now()->addDays(2)->toIso8601String(), 'is_active' => true]))->assertCreated();
        $this->postJson('/api/v1/promotions/'.$active->id.'/restore')->assertUnprocessable();
        Promotion::query()->where('id', '!=', $active->id)->delete();
        $this->postJson('/api/v1/promotions/'.$active->id.'/restore')->assertOk()->assertJsonPath('is_archived', false);
        $this->getJson('/api/v1/promotions/targets')->assertOk()->assertJsonPath('data.services.0.name', 'Visita')->assertJsonPath('data.checkups.0.name', 'Check');
        $service->update(['is_active' => false]);
        $this->getJson('/api/v1/promotions/'.$active->id)->assertJsonPath('lifecycle_status', 'active')->assertJsonPath('target_is_operational', false)->assertJsonPath('is_effectively_available', false);
    }

    #[Test]
    public function it_manages_an_optional_editorial_image_and_projects_its_public_url(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        $promotion = $this->create($this->service('Visita', 100), now()->subHour(), now()->addDay(), true);

        $this->getJson('/api/v1/promotions/'.$promotion->id)->assertOk()
            ->assertJsonPath('image_path', null)
            ->assertJsonPath('image_url', null);

        $this->post('/api/v1/promotions/'.$promotion->id.'/image', ['image' => UploadedFile::fake()->image('first.jpg')])
            ->assertOk()
            ->assertJsonPath('image_url', fn (string $url): bool => str_contains($url, "promotions/{$promotion->id}/images"));
        $firstPath = $promotion->refresh()->image_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->post('/api/v1/promotions/'.$promotion->id.'/image', ['image' => UploadedFile::fake()->image('second.jpg')])
            ->assertOk();
        $secondPath = $promotion->refresh()->image_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->deleteJson('/api/v1/promotions/'.$promotion->id.'/image')->assertOk()
            ->assertJsonPath('image_path', null)
            ->assertJsonPath('image_url', null);
        Storage::disk('public')->assertMissing($secondPath);
    }

    private function create(Service $service, $start, $end, bool $active): Promotion
    {
        return Promotion::query()->create($this->payload(['service_id' => $service->id, 'start_at' => $start, 'end_at' => $end, 'is_active' => $active]));
    }

    private function payload(array $overrides = []): array
    {
        return [...['name' => 'Promo test', 'service_id' => null, 'checkup_id' => null, 'promotional_price' => 80, 'start_at' => now()->subHour()->toIso8601String(), 'end_at' => now()->addDay()->toIso8601String(), 'validity_basis' => 'booking_date', 'is_active' => false, 'internal_notes' => 'Interna'], ...$overrides];
    }

    private function service(string $name, float $price): Service
    {
        return Service::query()->create(['display_name' => $name, 'canonical_name' => $name, 'slug' => str($name)->slug(), 'importo_prestazione' => $price, 'is_active' => true]);
    }

    private function checkup(string $name, float $price): Checkup
    {
        return Checkup::query()->create(['display_name' => $name, 'price_amount' => $price, 'indicative_duration_minutes' => 30, 'is_active' => true]);
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
