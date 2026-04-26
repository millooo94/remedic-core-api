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
            'subject_type' => 'individual',
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

    #[Test]
    public function professional_avatar_can_be_added_in_update_with_file_only_payload(): void
    {
        Storage::fake('public');
        $this->simulateMismatchedAppUrl();

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $professional = Professional::factory()->create([
            'subject_type' => 'individual',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Rossi Mario',
            'area_name' => 'Cardiologia',
            'email' => 'mario.rossi@example.com',
            'iban' => 'IT60X0542811101000000123456',
            'is_active' => true,
            'notes' => 'Note iniziali',
            'avatar_path' => null,
        ]);

        $response = $this->withBackendHost()->post("/api/v1/professionals/{$professional->id}", [
            '_method' => 'PUT',
            'avatar' => UploadedFile::fake()->image('doctor-update.png', 240, 240),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();

        $professional = $professional->fresh();
        $this->assertNotNull($professional?->avatar_path);
        $this->assertSame('Mario', $professional?->first_name);
        $this->assertSame('Rossi', $professional?->last_name);
        $this->assertSame('Cardiologia', $professional?->area_name);
        Storage::disk('public')->assertExists($professional->avatar_path);

        $expectedUrl = 'http://localhost:8000/storage/'.$professional->avatar_path;
        $response->assertJsonPath('avatar_url', $expectedUrl);
        $response->assertJsonPath('first_name', 'Mario');
        $response->assertJsonPath('last_name', 'Rossi');
        $response->assertJsonPath('area_name', 'Cardiologia');
    }

    #[Test]
    public function professional_avatar_can_be_replaced_in_update_and_old_file_is_removed(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $professional = Professional::factory()->create([
            'subject_type' => 'individual',
            'first_name' => 'Sara',
            'last_name' => 'Verdi',
            'full_name' => 'Verdi Sara',
            'area_name' => 'Dermatologia',
        ]);

        $oldAvatarPath = UploadedFile::fake()->image('old.png', 240, 240)
            ->store("professional-avatars/{$professional->id}", 'public');
        $professional->forceFill(['avatar_path' => $oldAvatarPath])->save();

        $response = $this->post("/api/v1/professionals/{$professional->id}", [
            '_method' => 'PUT',
            'subject_type' => 'individual',
            'first_name' => 'Sara',
            'last_name' => 'Verdi',
            'area_name' => 'Dermatologia',
            'area_names' => ['Dermatologia'],
            'email' => '',
            'iban' => '',
            'is_active' => '1',
            'notes' => '',
            'avatar' => UploadedFile::fake()->image('new.png', 240, 240),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();

        $professional = $professional->fresh();
        $this->assertNotNull($professional?->avatar_path);
        $this->assertNotSame($oldAvatarPath, $professional?->avatar_path);
        Storage::disk('public')->assertMissing($oldAvatarPath);
        Storage::disk('public')->assertExists($professional->avatar_path);
    }

    #[Test]
    public function frontend_style_multipart_payload_with_indexed_area_names_is_accepted_in_create_and_update(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $createResponse = $this->post('/api/v1/professionals', [
            'subject_type' => 'individual',
            'first_name' => 'Giulia',
            'last_name' => 'Bianchi',
            'area_name' => 'Cardiologia',
            'area_names[0]' => 'Cardiologia',
            'is_active' => '1',
            'email' => '',
            'iban' => '',
            'notes' => '',
            'avatar' => UploadedFile::fake()->image('create.png', 220, 220),
        ], [
            'Accept' => 'application/json',
        ]);

        $createResponse->assertCreated();
        $professionalId = (int) $createResponse->json('id');
        $created = Professional::query()->findOrFail($professionalId);
        $this->assertNotNull($created->avatar_path);
        Storage::disk('public')->assertExists($created->avatar_path);

        $updateResponse = $this->post("/api/v1/professionals/{$professionalId}", [
            '_method' => 'PUT',
            'subject_type' => 'individual',
            'first_name' => 'Giulia',
            'last_name' => 'Bianchi',
            'area_name' => 'Cardiologia',
            'area_names[0]' => 'Cardiologia',
            'is_active' => '1',
            'email' => '',
            'iban' => '',
            'notes' => '',
            'avatar' => UploadedFile::fake()->image('update.png', 220, 220),
        ], [
            'Accept' => 'application/json',
        ]);

        $updateResponse->assertOk();
        $updated = $created->fresh();
        $this->assertNotNull($updated?->avatar_path);
        Storage::disk('public')->assertExists($updated->avatar_path);
        $this->assertSame('Giulia', $updated?->first_name);
        $this->assertSame('Bianchi', $updated?->last_name);
        $this->assertSame('Cardiologia', $updated?->area_name);
    }

    #[Test]
    public function frontend_style_multipart_payload_with_area_names_json_is_accepted_in_create_and_update(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $createResponse = $this->post('/api/v1/professionals', [
            'subject_type' => 'individual',
            'first_name' => 'Luca',
            'last_name' => 'Neri',
            'area_name' => 'Dermatologia',
            'area_names' => json_encode(['Dermatologia']),
            'is_active' => '1',
            'email' => '',
            'iban' => '',
            'notes' => '',
            'avatar' => UploadedFile::fake()->image('create-json.png', 220, 220),
        ], [
            'Accept' => 'application/json',
        ]);

        $createResponse->assertCreated();
        $professionalId = (int) $createResponse->json('id');
        $created = Professional::query()->findOrFail($professionalId);
        $this->assertSame('Dermatologia', $created->area_name);
        $this->assertNotNull($created->avatar_path);
        Storage::disk('public')->assertExists($created->avatar_path);

        $updateResponse = $this->post("/api/v1/professionals/{$professionalId}", [
            '_method' => 'PUT',
            'subject_type' => 'individual',
            'first_name' => 'Luca',
            'last_name' => 'Neri',
            'area_name' => 'Dermatologia',
            'area_names' => json_encode(['Dermatologia']),
            'is_active' => '1',
            'email' => '',
            'iban' => '',
            'notes' => '',
            'avatar' => UploadedFile::fake()->image('update-json.png', 220, 220),
        ], [
            'Accept' => 'application/json',
        ]);

        $updateResponse->assertOk();
        $updated = $created->fresh();
        $this->assertSame('Dermatologia', $updated?->area_name);
        $this->assertNotNull($updated?->avatar_path);
        Storage::disk('public')->assertExists($updated->avatar_path);
    }

    private function withBackendHost(): self
    {
        return $this
            ->withHeaders([
                'Host' => 'localhost:8000',
                'X-Forwarded-Host' => 'localhost:8000',
                'X-Forwarded-Proto' => 'http',
                'X-Forwarded-Port' => '8000',
            ])
            ->withServerVariables([
                'HTTP_HOST' => 'localhost:8000',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '8000',
                'REQUEST_SCHEME' => 'http',
                'HTTPS' => 'off',
            ]);
    }

    private function simulateMismatchedAppUrl(): void
    {
        config()->set('app.url', 'http://localhost');
        config()->set('filesystems.disks.public.url', 'http://localhost/storage');
    }
}
