<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\HomePagePublicProjection;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomePageHeroMediaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['email' => 'homepage-hero-media-admin@example.com']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function hero_video_and_fallback_are_persisted_in_typed_slots_and_projected_with_public_urls(): void
    {
        Storage::fake('public');
        $home = Page::query()->create([
            'internal_key' => Page::HOME_INTERNAL_KEY,
            'title' => 'Homepage',
            'slug' => Page::HOME_SLUG,
            'template' => 'default',
            'is_active' => true,
        ]);
        $home->sections()->create(['key' => 'hero', 'title' => 'Hero', 'sort_order' => 0, 'is_active' => true, 'extra_json' => []]);

        $this->putJson("/api/v1/admin/pages/{$home->id}", [
            'title' => 'Homepage',
            'sections' => [[
                'key' => 'hero',
                'title' => 'Hero',
                'sort_order' => 0,
                'is_active' => true,
                'data' => [
                    'primary_cta_label' => 'Prenota una visita',
                    'primary_cta_target' => 'booking',
                    'secondary_cta_label' => 'Scopri le aree mediche',
                    'secondary_cta_target' => 'medical_areas_index',
                ],
            ]],
        ])->assertOk();
        $configuredHero = $home->fresh()->sections()->where('key', 'hero')->firstOrFail();
        $this->assertSame('booking', $configuredHero->extra_json['primary_cta_target']);
        $this->assertSame('medical_areas_index', $configuredHero->extra_json['secondary_cta_target']);

        $this->putJson("/api/v1/admin/pages/{$home->id}", [
            'title' => 'Homepage',
            'sections' => [[
                'key' => 'hero',
                'title' => 'Hero',
                'sort_order' => 0,
                'is_active' => true,
                'data' => ['primary_cta_target' => 'invalid-target'],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('sections.0.data.primary_cta_target');

        $video = $this->post('/api/v1/admin/pages/media', [
            'page_id' => $home->id,
            'section_key' => 'hero',
            'media_slot' => 'hero_video',
            'image' => UploadedFile::fake()->create('hero.mp4', 12000, 'video/mp4'),
        ])->assertOk()->assertJsonPath('media_slot', 'hero_video');
        $videoPath = (string) $video->json('media_path');
        Storage::disk('public')->assertExists($videoPath);

        $this->post('/api/v1/admin/pages/media', [
            'page_id' => $home->id,
            'section_key' => 'hero',
            'media_slot' => 'hero_video',
            'image' => UploadedFile::fake()->image('not-a-video.jpg', 1200, 800),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');

        $poster = $this->post('/api/v1/admin/pages/media', [
            'page_id' => $home->id,
            'section_key' => 'hero',
            'media_slot' => 'hero_poster',
            'image' => UploadedFile::fake()->image('hero.jpg', 1200, 800),
        ])->assertOk()->assertJsonPath('media_slot', 'hero_poster');
        $posterPath = (string) $poster->json('media_path');
        Storage::disk('public')->assertExists($posterPath);

        $extra = $home->sections()->where('key', 'hero')->firstOrFail()->extra_json;
        $this->assertSame($videoPath, $extra['media']['hero_video']['path']);
        $this->assertSame($posterPath, $extra['media']['hero_poster']['path']);
        $this->assertArrayNotHasKey('image_path', $extra);

        $hero = collect(app(HomePagePublicProjection::class)->project($home->fresh(), Request::create('/'))['sections'])->firstWhere('key', 'hero');
        $this->assertStringContainsString($videoPath, $hero['data']['media']['hero_video']['url']);
        $this->assertStringContainsString($posterPath, $hero['data']['media']['hero_poster']['url']);
        $this->assertSame(['label' => 'Prenota una visita', 'action' => 'booking'], $hero['data']['primary_cta']);

        $this->deleteJson("/api/v1/admin/pages/{$home->id}/sections/hero/media?media_slot=hero_video")
            ->assertOk()
            ->assertJsonPath('media_slot', 'hero_video')
            ->assertJsonPath('media_path', null);
        Storage::disk('public')->assertMissing($videoPath);
        Storage::disk('public')->assertExists($posterPath);

        $this->deleteJson("/api/v1/admin/pages/{$home->id}/sections/hero/media?media_slot=hero_poster")
            ->assertOk()
            ->assertJsonPath('media_slot', 'hero_poster')
            ->assertJsonPath('media_path', null);
        Storage::disk('public')->assertMissing($posterPath);
    }

    #[Test]
    public function center_intro_persists_its_shared_cta_target_and_image_slot(): void
    {
        Storage::fake('public');
        Page::query()->create([
            'internal_key' => 'center',
            'title' => 'Il centro',
            'slug' => 'il-centro',
            'template' => 'default',
            'is_active' => true,
            'published_at' => now()->subSecond(),
        ]);
        $home = Page::query()->create([
            'internal_key' => Page::HOME_INTERNAL_KEY,
            'title' => 'Homepage',
            'slug' => Page::HOME_SLUG,
            'template' => 'default',
            'is_active' => true,
        ]);
        $home->sections()->create(['key' => 'center_intro', 'title' => 'Introduzione centro', 'sort_order' => 1, 'is_active' => true, 'extra_json' => []]);

        $this->putJson("/api/v1/admin/pages/{$home->id}", [
            'title' => 'Homepage',
            'sections' => [[
                'key' => 'center_intro',
                'title' => 'Introduzione centro editoriale',
                'sort_order' => 1,
                'is_active' => true,
                'data' => [
                    'title' => 'Un centro, un unico percorso di cura',
                    'cta_label' => 'Scopri Remedic',
                    'cta_target' => 'center',
                ],
            ]],
        ])->assertOk();
        $section = $home->fresh()->sections()->where('key', 'center_intro')->firstOrFail();
        $this->assertSame('Introduzione centro editoriale', $section->title);
        $this->assertSame('Un centro, un unico percorso di cura', $section->extra_json['title']);
        $this->assertSame('center', $section->extra_json['cta_target']);

        $this->putJson("/api/v1/admin/pages/{$home->id}", [
            'title' => 'Homepage',
            'sections' => [[
                'key' => 'center_intro',
                'title' => 'Introduzione centro',
                'sort_order' => 1,
                'is_active' => true,
                'data' => ['cta_target' => 'invalid-target'],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('sections.0.data.cta_target');

        $upload = $this->post('/api/v1/admin/pages/media', [
            'page_id' => $home->id,
            'section_key' => 'center_intro',
            'media_slot' => 'center_intro',
            'image' => UploadedFile::fake()->image('centro.jpg', 1200, 800),
        ])->assertOk()->assertJsonPath('media_slot', 'center_intro');
        $path = (string) $upload->json('media_path');
        Storage::disk('public')->assertExists($path);
        $this->assertSame($path, $home->fresh()->sections()->where('key', 'center_intro')->firstOrFail()->extra_json['media']['center_intro']['path']);

        $intro = collect(app(HomePagePublicProjection::class)->project($home->fresh(), Request::create('/'))['sections'])->firstWhere('key', 'center_intro');
        $this->assertSame(['label' => 'Scopri Remedic', 'href' => '/il-centro'], $intro['data']['cta']);
        $this->assertStringContainsString($path, $intro['data']['media']['center_intro']['url']);
    }

    #[Test]
    public function homepage_ctas_resolve_external_urls_and_master_whatsapp_without_duplicating_contacts(): void
    {
        SiteSetting::ensureSingleton()->update(['whatsapp_number' => '+39 333 1234567']);
        $home = Page::query()->create(['internal_key' => Page::HOME_INTERNAL_KEY, 'title' => 'Homepage', 'slug' => Page::HOME_SLUG, 'template' => 'default', 'is_active' => true]);
        $home->sections()->create(['key' => 'center_intro', 'title' => 'Introduzione centro', 'sort_order' => 1, 'is_active' => true, 'extra_json' => []]);

        $payload = ['title' => 'Homepage', 'sections' => [[
            'key' => 'center_intro', 'title' => 'Introduzione centro', 'sort_order' => 1, 'is_active' => true,
            'data' => ['cta_label' => 'Approfondisci', 'cta_target' => 'external_url', 'cta_external_url' => 'https://example.test/approfondisci'],
        ]]];
        $this->putJson("/api/v1/admin/pages/{$home->id}", $payload)->assertOk();
        $section = $home->fresh()->sections()->where('key', 'center_intro')->firstOrFail();
        $this->assertSame('https://example.test/approfondisci', $section->extra_json['cta_external_url']);

        $intro = collect(app(HomePagePublicProjection::class)->project($home->fresh(), Request::create('/'))['sections'])->firstWhere('key', 'center_intro');
        $this->assertSame(['label' => 'Approfondisci', 'action' => 'external_url', 'href' => 'https://example.test/approfondisci'], $intro['data']['cta']);

        $payload['sections'][0]['data'] = ['cta_label' => 'Scrivici', 'cta_target' => 'whatsapp', 'cta_whatsapp_message' => 'Buongiorno Remedic'];
        $this->putJson("/api/v1/admin/pages/{$home->id}", $payload)->assertOk();
        $intro = collect(app(HomePagePublicProjection::class)->project($home->fresh(), Request::create('/'))['sections'])->firstWhere('key', 'center_intro');
        $this->assertSame('https://wa.me/393331234567?text=Buongiorno%20Remedic', $intro['data']['cta']['href']);

        $payload['sections'][0]['data'] = ['cta_label' => 'No', 'cta_target' => 'external_url', 'cta_external_url' => 'http://example.test'];
        $this->putJson("/api/v1/admin/pages/{$home->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('sections.0.data.cta_external_url');
    }
}
