<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaUploadApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_avatar_is_stored_and_reloaded_with_the_request_host_url(): void
    {
        Storage::fake('public');
        $this->simulateMismatchedAppUrl();

        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->withBackendHost()->post('/api/v1/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('profile.jpg', 240, 240),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();

        $user = $user->fresh();
        $this->assertNotNull($user?->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);

        $expectedUrl = 'http://localhost:8000/storage/'.$user->avatar_path;

        $response->assertJsonPath('user.avatar_url', $expectedUrl);

        $this->withBackendHost()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('avatar_url', $expectedUrl);
    }

    #[Test]
    public function professional_avatar_is_stored_and_reloaded_with_the_request_host_url(): void
    {
        Storage::fake('public');
        $this->simulateMismatchedAppUrl();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->withBackendHost()->post('/api/v1/professionals', [
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'area_name' => 'Cardiologia',
            'area_names' => ['Cardiologia'],
            'is_active' => '1',
            'avatar' => UploadedFile::fake()->image('doctor.png', 240, 240),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated();

        $professional = Professional::query()->findOrFail((int) $response->json('id'));
        $this->assertNotNull($professional->avatar_path);
        Storage::disk('public')->assertExists($professional->avatar_path);

        $expectedUrl = 'http://localhost:8000/storage/'.$professional->avatar_path;

        $response->assertJsonPath('avatar_url', $expectedUrl);

        $this->withBackendHost()
            ->getJson('/api/v1/professionals/'.$professional->id)
            ->assertOk()
            ->assertJsonPath('avatar_url', $expectedUrl);
    }

    private function withBackendHost(): self
    {
        return $this->withServerVariables([
            'HTTP_HOST' => 'localhost:8000',
            'SERVER_PORT' => '8000',
        ]);
    }

    private function simulateMismatchedAppUrl(): void
    {
        config()->set('app.url', 'http://localhost');
        config()->set('filesystems.disks.public.url', 'http://localhost/storage');
    }
}
