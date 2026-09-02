<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LocalizedSeoContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['email' => 'localized-seo-admin@example.test']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function homepage_seo_translations_are_isolated_for_every_supported_locale(): void
    {
        $home = Page::query()->create([
            'internal_key' => Page::HOME_INTERNAL_KEY,
            'title' => 'Homepage',
            'slug' => 'home',
            'template' => 'default',
            'is_active' => true,
            'seo_title' => 'Biostimolazione',
            'seo_description' => 'Descrizione italiana',
            'og_title' => 'OG italiano',
            'og_description' => 'OG descrizione italiana',
            'twitter_title' => 'Twitter italiano',
            'twitter_description' => 'Twitter descrizione italiana',
        ]);

        $values = [
            'en' => ['Biostimulation', 'English description'],
            'es' => ['Bioestimulación', 'Descripción española'],
            'fr' => ['Biostimulation FR', 'Description française'],
        ];
        foreach ($values as $locale => [$title, $description]) {
            $endpoint = "/api/v1/admin/localized-content/pages/{$home->id}/{$locale}";
            $this->getJson($endpoint)->assertOk()->assertJsonPath('data.status', 'missing');
            $this->postJson($endpoint)->assertCreated();
            $this->putJson($endpoint, [
                'seo_title' => $title,
                'seo_description' => $description,
                'og_title' => "OG {$locale}",
                'og_description' => "OG description {$locale}",
                'twitter_title' => "Twitter {$locale}",
                'twitter_description' => "Twitter description {$locale}",
            ])->assertOk()->assertJsonPath('data.translation.seo_title', $title);
        }

        foreach ($values as $locale => [$title, $description]) {
            $this->getJson("/api/v1/admin/localized-content/pages/{$home->id}/{$locale}")
                ->assertOk()
                ->assertJsonPath('data.translation.seo_title', $title)
                ->assertJsonPath('data.translation.seo_description', $description);
        }
        $this->assertDatabaseHas('content_translations', [
            'translatable_type' => Page::class,
            'translatable_id' => $home->id,
            'locale' => 'it',
            'seo_title' => 'Biostimolazione',
            'twitter_title' => 'Twitter italiano',
        ]);
    }

    #[Test]
    public function service_seo_twitter_and_local_seo_translations_are_isolated_while_the_toggle_is_global(): void
    {
        $service = $this->service('Prestazione localizzata');
        $profile = ServiceWebProfile::query()->create([
            'service_id' => $service->id,
            'public_slug' => 'biostimolazione',
            'is_web_enabled' => true,
            'is_local_seo_enabled' => true,
            'seo_title' => 'Biostimolazione',
            'seo_h1' => 'H1 italiano',
            'seo_description' => 'Descrizione italiana',
            'og_title' => 'OG italiano',
            'og_description' => 'OG descrizione italiana',
            'twitter_title' => 'Twitter italiano',
            'twitter_description' => 'Twitter descrizione italiana',
            'local_seo_title' => 'Local italiano',
            'local_seo_h1' => 'Local H1 italiano',
            'local_seo_description' => 'Local descrizione italiana',
        ]);

        foreach (['en' => 'English', 'es' => 'Español', 'fr' => 'Français'] as $locale => $label) {
            $endpoint = "/api/v1/admin/localized-content/services/{$profile->id}/{$locale}";
            $this->postJson($endpoint)->assertCreated();
            $this->putJson($endpoint, [
                'seo_title' => "SEO {$label}", 'seo_h1' => "H1 {$label}", 'seo_description' => "Description {$label}",
                'og_title' => "OG {$label}", 'og_description' => "OG description {$label}",
                'twitter_title' => "Twitter {$label}", 'twitter_description' => "Twitter description {$label}",
                'local_seo_title' => "Local {$label}", 'local_seo_h1' => "Local H1 {$label}", 'local_seo_description' => "Local description {$label}",
            ])->assertOk()->assertJsonPath('data.translation.local_seo_title', "Local {$label}");
        }

        $profile->update(['is_local_seo_enabled' => false]);
        foreach (['en' => 'English', 'es' => 'Español', 'fr' => 'Français'] as $locale => $label) {
            $this->getJson("/api/v1/admin/localized-content/services/{$profile->id}/{$locale}")
                ->assertOk()
                ->assertJsonPath('data.translation.seo_title', "SEO {$label}")
                ->assertJsonPath('data.translation.twitter_title', "Twitter {$label}")
                ->assertJsonPath('data.translation.local_seo_description', "Local description {$label}");
        }
        $this->assertFalse($profile->fresh()->is_local_seo_enabled);
        $this->assertDatabaseHas('content_translations', [
            'translatable_type' => ServiceWebProfile::class,
            'translatable_id' => $profile->id,
            'locale' => 'it',
            'twitter_title' => 'Twitter italiano',
            'local_seo_h1' => 'Local H1 italiano',
        ]);
    }

    #[Test]
    public function service_twitter_image_is_a_global_profile_asset(): void
    {
        Storage::fake('public');
        $service = $this->service('Prestazione Twitter');
        ServiceWebProfile::query()->create([
            'service_id' => $service->id,
            'public_slug' => 'profilo-twitter',
            'is_web_enabled' => true,
            'is_local_seo_enabled' => true,
        ]);

        $this->postJson("/api/v1/admin/prestazioni/{$service->id}/twitter-image", [
            'image' => UploadedFile::fake()->image('twitter.png'),
        ])->assertOk()->assertJsonPath('web_profile.twitter_title', null);

        $path = $service->fresh()->webProfile->twitter_image_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    private function service(string $name): Service
    {
        return Service::query()->create([
            'category_id' => null,
            'canonical_name' => $name,
            'display_name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(8)),
            'description' => null,
            'default_duration_minutes' => null,
            'is_active' => true,
            'is_web_active' => false,
        ]);
    }
}
