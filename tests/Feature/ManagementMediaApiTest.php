<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Checkup;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Service;
use App\Models\Specialization;
use App\Models\User;
use App\Services\ProfessionalAvatarBackfill;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManagementMediaApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function professional_avatar_can_be_uploaded_replaced_and_deleted_idempotently(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $professional = Professional::factory()->create();

        $first = $this->post("/api/v1/professionals/{$professional->id}/image", [
            'image' => UploadedFile::fake()->image('avatar.jpg')->size(800),
        ])->assertOk()
            ->assertJsonPath('avatar_path', fn (string $path): bool => str_starts_with($path, "professionals/{$professional->id}/"))
            ->assertJsonPath('avatar_url', fn (string $url): bool => str_contains($url, '/storage/professionals/'));
        $firstPath = $first->json('avatar_path');
        Storage::disk('public')->assertExists($firstPath);

        $secondPath = $this->post("/api/v1/professionals/{$professional->id}/image", [
            'image' => UploadedFile::fake()->image('replacement.png')->size(900),
        ])->assertOk()->json('avatar_path');

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->deleteJson("/api/v1/professionals/{$professional->id}/image")
            ->assertOk()
            ->assertJsonPath('avatar_path', null)
            ->assertJsonPath('avatar_url', null);
        Storage::disk('public')->assertMissing($secondPath);

        $this->deleteJson("/api/v1/professionals/{$professional->id}/image")
            ->assertOk()
            ->assertJsonPath('avatar_path', null);
    }

    #[Test]
    public function image_validation_rejects_invalid_mime_and_oversized_files_without_losing_current_media(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $professional = Professional::factory()->create();
        $oldPath = "professionals/{$professional->id}/existing.jpg";
        Storage::disk('public')->put($oldPath, 'existing');
        $professional->forceFill(['avatar_path' => $oldPath])->save();

        $this->withHeader('Accept', 'application/json')->post("/api/v1/professionals/{$professional->id}/image", [
            'image' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['image']);

        $this->withHeader('Accept', 'application/json')->post("/api/v1/professionals/{$professional->id}/image", [
            'image' => UploadedFile::fake()->image('too-large.jpg')->size(5121),
        ])->assertUnprocessable()->assertJsonValidationErrors(['image']);

        $this->assertSame($oldPath, $professional->refresh()->avatar_path);
        Storage::disk('public')->assertExists($oldPath);
    }

    #[Test]
    public function media_delete_clears_legacy_paths_without_deleting_files_outside_managed_storage(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $professional = Professional::factory()->create(['avatar_path' => 'legacy-imports/doctor.jpg']);
        Storage::disk('public')->put('legacy-imports/doctor.jpg', 'legacy');

        $this->deleteJson("/api/v1/professionals/{$professional->id}/image")
            ->assertOk()
            ->assertJsonPath('avatar_path', null);
        Storage::disk('public')->assertExists('legacy-imports/doctor.jpg');

        $professional->forceFill(['avatar_path' => 'https://legacy.example.test/doctor.jpg'])->save();
        $this->deleteJson("/api/v1/professionals/{$professional->id}/image")
            ->assertOk()
            ->assertJsonPath('avatar_path', null);
    }

    #[Test]
    public function legacy_profile_images_are_backfilled_without_overwriting_existing_avatars(): void
    {
        $missingAvatar = Professional::factory()->create(['avatar_path' => null]);
        $existingAvatar = Professional::factory()->create(['avatar_path' => 'professionals/existing/master.jpg']);
        ProfessionalPublicProfile::query()->create([
            'professional_id' => $missingAvatar->id,
            'slug' => 'legacy-missing-avatar',
            'profile_image_path' => 'legacy/missing-avatar.jpg',
        ]);
        ProfessionalPublicProfile::query()->create([
            'professional_id' => $existingAvatar->id,
            'slug' => 'legacy-existing-avatar',
            'profile_image_path' => 'legacy/must-not-win.jpg',
        ]);

        $this->assertSame(1, app(ProfessionalAvatarBackfill::class)->run());
        $this->assertSame('legacy/missing-avatar.jpg', $missingAvatar->refresh()->avatar_path);
        $this->assertSame('professionals/existing/master.jpg', $existingAvatar->refresh()->avatar_path);
    }

    #[Test]
    public function specialization_master_image_and_icon_support_replace_delete_urls_and_exclude_svg(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $specialization = $this->specialization('Cardiologia');

        $imagePath = $this->post("/api/v1/specializations/{$specialization->id}/image", [
            'image' => UploadedFile::fake()->image('area.png')->size(700),
        ])->assertOk()
            ->assertJsonPath('featured_image_url', fn (string $url): bool => str_contains($url, '/storage/specializations/'))
            ->json('featured_image_path');

        $firstIconPath = $this->post("/api/v1/specializations/{$specialization->id}/icon", [
            'icon' => UploadedFile::fake()->image('icon.png', 128, 128)->size(80),
        ])->assertOk()
            ->assertJsonPath('icon_url', fn (string $url): bool => str_contains($url, '/storage/specializations/'))
            ->json('icon_path');

        $secondIconPath = $this->post("/api/v1/specializations/{$specialization->id}/icon", [
            'icon' => UploadedFile::fake()->image('replacement.png', 128, 128)->size(80),
        ])->assertOk()->json('icon_path');
        Storage::disk('public')->assertMissing($firstIconPath);
        Storage::disk('public')->assertExists($secondIconPath);

        $this->withHeader('Accept', 'application/json')->post("/api/v1/specializations/{$specialization->id}/icon", [
            'icon' => UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['icon']);
        $this->assertSame($secondIconPath, $specialization->refresh()->icon_path);

        $this->deleteJson("/api/v1/specializations/{$specialization->id}/image")->assertOk();
        $this->deleteJson("/api/v1/specializations/{$specialization->id}/icon")->assertOk();
        Storage::disk('public')->assertMissing($imagePath);
        Storage::disk('public')->assertMissing($secondIconPath);
    }

    #[Test]
    public function service_uses_a_master_image_and_derives_its_icon_from_the_primary_specialization(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $first = $this->specialization('Cardiologia', 'specializations/1/icons/cardio.png');
        $second = $this->specialization('Diagnostica', 'specializations/2/icons/diagnostica.png');
        $service = $this->atomicService('Prestazione icona primaria');
        $service->specializations()->attach([
            $first->id => ['is_primary' => true, 'sort_order' => 1],
            $second->id => ['is_primary' => false, 'sort_order' => 0],
        ]);

        $this->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('inherited_icon_path', $first->icon_path);

        $service->specializations()->updateExistingPivot($first->id, ['is_primary' => false]);
        $service->specializations()->updateExistingPivot($second->id, ['is_primary' => true]);
        $this->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('inherited_icon_path', $second->icon_path);

        $imagePath = $this->post("/api/v1/services/{$service->id}/image", [
            'image' => UploadedFile::fake()->image('service.jpg')->size(900),
        ])->assertOk()
            ->assertJsonPath('featured_image_url', fn (string $url): bool => str_contains($url, '/storage/services/'))
            ->json('featured_image_path');
        $this->assertFalse(Schema::hasColumn('services', 'icon_path'));

        $this->deleteJson("/api/v1/services/{$service->id}/image")
            ->assertOk()
            ->assertJsonPath('featured_image_path', null);
        Storage::disk('public')->assertMissing($imagePath);
    }

    #[Test]
    public function legacy_services_without_a_primary_specialization_use_deterministic_sort_order_fallback(): void
    {
        $this->actingAsManager();
        $first = $this->specialization('Prima', 'icons/first.png');
        $second = $this->specialization('Seconda', 'icons/second.png');
        $service = $this->atomicService('Prestazione fallback icona');
        $service->specializations()->attach([
            $first->id => ['is_primary' => false, 'sort_order' => 5],
            $second->id => ['is_primary' => false, 'sort_order' => 2],
        ]);

        $this->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('inherited_icon_path', $second->icon_path);
    }

    #[Test]
    public function checkup_master_image_and_icon_can_be_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');
        $this->actingAsManager();
        $service = $this->atomicService('Prestazione Check-up media');
        $checkup = Checkup::factory()->create();
        $checkup->items()->create(['service_id' => $service->id, 'sort_order' => 0]);

        $imagePath = $this->post("/api/v1/checkups/{$checkup->id}/image", [
            'image' => UploadedFile::fake()->image('checkup.jpg')->size(900),
        ])->assertOk()->json('featured_image_path');
        $firstIconPath = $this->post("/api/v1/checkups/{$checkup->id}/icon", [
            'icon' => UploadedFile::fake()->image('checkup.png', 128, 128)->size(80),
        ])->assertOk()->json('icon_path');
        $secondIconPath = $this->post("/api/v1/checkups/{$checkup->id}/icon", [
            'icon' => UploadedFile::fake()->image('checkup-new.png', 128, 128)->size(80),
        ])->assertOk()
            ->assertJsonPath('featured_image_url', fn (string $url): bool => str_contains($url, '/storage/checkups/'))
            ->assertJsonPath('icon_url', fn (string $url): bool => str_contains($url, '/storage/checkups/'))
            ->json('icon_path');

        Storage::disk('public')->assertMissing($firstIconPath);
        Storage::disk('public')->assertExists($secondIconPath);
        $this->deleteJson("/api/v1/checkups/{$checkup->id}/image")->assertOk();
        $this->deleteJson("/api/v1/checkups/{$checkup->id}/icon")->assertOk();
        Storage::disk('public')->assertMissing($imagePath);
        Storage::disk('public')->assertMissing($secondIconPath);
    }

    #[Test]
    public function media_endpoints_require_the_same_management_authorization_as_crud(): void
    {
        $professional = Professional::factory()->create();

        $this->postJson("/api/v1/professionals/{$professional->id}/image")
            ->assertUnauthorized();
    }

    #[Test]
    public function web_admin_requests_cannot_change_master_media_but_can_keep_editorial_social_media(): void
    {
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);

        $professional = Professional::factory()->create(['avatar_path' => 'professionals/master.jpg']);
        $profile = ProfessionalPublicProfile::query()->create([
            'professional_id' => $professional->id,
            'slug' => 'doctor-media-owner',
            'profile_image_path' => 'legacy/profile.jpg',
        ]);
        $this->putJson("/api/v1/admin/professional-public-profiles/{$profile->id}", [
            'professional_id' => $professional->id,
            'slug' => $profile->slug,
            'profile_image_path' => 'web/new-main.jpg',
        ])->assertUnprocessable()->assertJsonValidationErrors(['profile_image_path']);
        $this->assertSame('professionals/master.jpg', $professional->refresh()->avatar_path);
        $this->getJson("/api/v1/admin/professional-public-profiles/{$profile->id}")
            ->assertOk()
            ->assertJsonPath('profile_image_path', 'legacy/profile.jpg')
            ->assertJsonPath('avatar_path', 'professionals/master.jpg')
            ->assertJsonPath('avatar_url', fn (string $url): bool => str_contains($url, '/storage/professionals/master.jpg'));

        $specialization = $this->specialization('Web locked');
        $this->putJson("/api/v1/admin/specializations/{$specialization->id}", [
            'slug' => $specialization->slug,
            'icon_path' => 'web/icon.png',
            'featured_image_path' => 'web/area.jpg',
        ])->assertUnprocessable()->assertJsonValidationErrors(['icon_path', 'featured_image_path']);

        $service = $this->atomicService('Prestazione Web media', [
            'featured_image_path' => 'services/master.jpg',
            'social_image_path' => null,
        ]);
        $this->putJson("/api/v1/admin/services/{$service->id}", [
            'slug' => $service->slug,
            'featured_image_path' => 'web/main.jpg',
            'social_image_path' => 'web/social.jpg',
        ])->assertUnprocessable()->assertJsonValidationErrors(['featured_image_path']);
        $this->assertSame('services/master.jpg', $service->refresh()->featured_image_path);

        $this->putJson("/api/v1/admin/services/{$service->id}", [
            'slug' => $service->slug,
            'social_image_path' => 'web/social.jpg',
        ])->assertOk();
        $this->assertSame('web/social.jpg', $service->refresh()->social_image_path);
    }

    #[Test]
    public function public_web_reads_management_master_media_urls(): void
    {
        $specialization = $this->specialization('Area pubblica', 'specializations/20/icons/icon.png');
        $specialization->forceFill([
            'featured_image_path' => 'specializations/20/images/area.jpg',
            'is_web_active' => true,
        ])->save();
        $service = $this->atomicService('Prestazione pubblica', [
            'featured_image_path' => 'services/30/images/service.jpg',
            'is_web_active' => true,
        ]);
        $service->specializations()->attach($specialization->id, [
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $publicSpecialization = collect($this->getJson('/api/v1/public/specializations')
            ->assertOk()
            ->json('data'))->firstWhere('slug', $specialization->slug);
        $this->assertStringContainsString('/storage/specializations/20/icons/icon.png', $publicSpecialization['icon_url']);
        $this->assertStringContainsString('/storage/specializations/20/images/area.jpg', $publicSpecialization['featured_image_url']);

        $publicService = collect($this->getJson('/api/v1/public/services')
            ->assertOk()
            ->json('data'))->firstWhere('slug', $service->slug);
        $this->assertStringContainsString('/storage/specializations/20/icons/icon.png', $publicService['icon_url']);
        $this->assertStringContainsString('/storage/services/30/images/service.jpg', $publicService['featured_image_url']);
    }

    private function actingAsManager(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function specialization(string $name, ?string $iconPath = null): Specialization
    {
        return Specialization::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
            'icon_path' => $iconPath,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function atomicService(string $name, array $attributes = []): Service
    {
        return Service::query()->create(array_merge([
            'category_id' => null,
            'canonical_name' => $name,
            'display_name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
            'is_active' => true,
        ], $attributes));
    }
}
