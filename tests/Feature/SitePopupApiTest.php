<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Page;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\SiteIndexPage;
use App\Models\SitePopup;
use App\Models\User;
use App\Services\SitePopupInitializer;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SitePopupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function initializer_is_a_missing_only_active_singleton_with_a_start_time(): void
    {
        $initializer = app(SitePopupInitializer::class);
        $popup = $initializer->initialize();
        $this->assertTrue($popup->is_active);
        $this->assertNotNull($popup->start_at);
        $popup->update(['title' => 'Conservato', 'is_active' => false]);
        $initializer->initialize();

        $this->assertSame(1, SitePopup::query()->count());
        $this->assertSame('Conservato', SitePopup::query()->firstOrFail()->title);
        $this->assertSame(1, $popup->campaign_version);
    }

    #[Test]
    public function admin_requires_its_granular_permission_and_validates_its_closed_schema(): void
    {
        $this->getJson('/api/v1/admin/site-popup')->assertUnauthorized();
        $this->actingAsWebAdmin();
        $this->getJson('/api/v1/admin/site-popup')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.campaign_version', 1);
        $this->putJson('/api/v1/admin/site-popup', ['is_active' => false, 'start_at' => null, 'end_at' => null, 'eyebrow' => null, 'title' => null, 'body' => null, 'primary_cta_label' => null, 'primary_cta_target' => null, 'secondary_cta_label' => null, 'secondary_cta_target' => null, 'unknown' => true])->assertUnprocessable();
        $this->putJson('/api/v1/admin/site-popup', $this->payload(['is_active' => true]))->assertUnprocessable();
        $this->putJson('/api/v1/admin/site-popup', $this->payload(['start_at' => now()->addDay()->toIso8601String(), 'end_at' => now()->addHour()->toIso8601String()]))->assertUnprocessable();
        $this->putJson('/api/v1/admin/site-popup', $this->payload(['primary_cta_label' => 'Vai']))->assertUnprocessable();
        $this->putJson('/api/v1/admin/site-popup', $this->payload(['primary_cta_label' => 'Vai', 'primary_cta_target' => 'non-esiste']))->assertUnprocessable();
    }

    #[Test]
    public function derived_statuses_and_public_eligibility_are_safe(): void
    {
        $this->actingAsWebAdmin();
        $this->getJson('/api/v1/public/site/popup')->assertOk()->assertJsonPath('data', null);
        $this->save(['is_active' => true, 'title' => 'Sempre'])->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/public/site/popup')->assertOk()->assertJsonPath('data.title', 'Sempre')->assertJsonMissingPath('data.is_active');
        $this->save(['start_at' => now()->addHour()->toIso8601String()])->assertJsonPath('data.status', 'scheduled');
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data', null);
        $this->save(['start_at' => now()->subHour()->toIso8601String(), 'end_at' => now()->addHour()->toIso8601String()])->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data.campaign_version', 1);
        $this->save(['end_at' => now()->subMinute()->toIso8601String()])->assertJsonPath('data.status', 'expired');
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data', null);
        $this->save(['is_active' => false, 'end_at' => null])->assertJsonPath('data.status', 'inactive');
    }

    #[Test]
    public function semantic_ctas_preserve_unpublished_configuration_and_omit_only_public_links(): void
    {
        $this->actingAsWebAdmin();
        Page::query()->create(['internal_key' => 'center', 'title' => 'Centro', 'slug' => 'centro', 'is_active' => true, 'published_at' => now()->subMinute()]);
        SiteIndexPage::query()->create(['internal_key' => 'news_index', 'title' => 'News', 'slug' => 'news', 'is_active' => false]);
        $this->save(['is_active' => true, 'title' => 'Popup', 'primary_cta_label' => 'Centro', 'primary_cta_target' => 'center', 'secondary_cta_label' => 'News', 'secondary_cta_target' => 'news_index'])
            ->assertJsonPath('data.primary_cta.href', '/centro')
            ->assertJsonPath('data.secondary_cta.publication_state', 'suspended');
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data.primary_cta.href', '/centro')->assertJsonPath('data.secondary_cta', null);
        $this->save(['secondary_cta_label' => 'Prenota', 'secondary_cta_target' => 'booking']);
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data.secondary_cta.action', 'booking');
    }

    #[Test]
    public function republish_changes_only_campaign_version_and_media_is_managed(): void
    {
        Storage::fake('public');
        $this->actingAsWebAdmin();
        $this->save(['is_active' => true, 'title' => 'Campagna', 'primary_cta_label' => 'Prenota', 'primary_cta_target' => 'booking']);
        $this->post('/api/v1/admin/site-popup/image', ['file' => UploadedFile::fake()->image('popup.jpg')])->assertOk()->assertJsonPath('data.image_url', fn (string $url): bool => str_contains($url, 'site-popup/image'));
        $before = SitePopup::query()->firstOrFail()->only(['title', 'is_active', 'primary_cta_label', 'primary_cta_target', 'campaign_version', 'image_path', 'source_type', 'promotion_id', 'event_id']);
        $this->postJson('/api/v1/admin/site-popup/republish')->assertOk()->assertJsonPath('data.campaign_version', 2)->assertJsonPath('data.title', 'Campagna');
        $after = SitePopup::query()->firstOrFail();
        $this->assertSame($before['title'], $after->title);
        $this->assertSame($before['image_path'], $after->image_path);
        $this->assertSame($before['source_type']->value, $after->source_type->value);
        $this->assertSame(2, $after->campaign_version);
        $this->deleteJson('/api/v1/admin/site-popup/image')->assertOk()->assertJsonPath('data.image_url', null);
    }

    #[Test]
    public function it_copies_only_managed_source_images_into_the_popup_storage(): void
    {
        Storage::fake('public');
        $this->actingAsWebAdmin();
        $service = Service::query()->create(['display_name' => 'Visita', 'canonical_name' => 'Visita', 'slug' => 'visita', 'importo_prestazione' => 100, 'is_active' => true]);
        $promotion = Promotion::query()->create(['name' => 'Promo', 'service_id' => $service->id, 'promotional_price' => 80, 'start_at' => now()->subHour(), 'end_at' => now()->addDay(), 'validity_basis' => 'booking_date', 'is_active' => true]);
        $sourcePath = "promotions/{$promotion->id}/images/source.jpg";
        Storage::disk('public')->put($sourcePath, 'source-image');
        $promotion->update(['image_path' => $sourcePath]);

        $this->postJson('/api/v1/admin/site-popup/source-image', ['source_type' => 'promotion', 'source_id' => $promotion->id])
            ->assertOk()
            ->assertJsonPath('data.image_url', fn (string $url): bool => str_contains($url, 'site-popup/image'));
        $popupPath = SitePopup::query()->firstOrFail()->image_path;
        $this->assertNotSame($sourcePath, $popupPath);
        Storage::disk('public')->assertExists($popupPath);
        $this->assertSame('source-image', Storage::disk('public')->get($popupPath));

        $promotion->update(['image_path' => null]);
        $this->postJson('/api/v1/admin/site-popup/source-image', ['source_type' => 'promotion', 'source_id' => $promotion->id])
            ->assertOk()
            ->assertJsonPath('data.image_path', null)
            ->assertJsonPath('data.image_url', null);
        Storage::disk('public')->assertMissing($popupPath);

        $promotion->update(['image_path' => 'https://example.test/not-managed.jpg']);
        $this->postJson('/api/v1/admin/site-popup/source-image', ['source_type' => 'promotion', 'source_id' => $promotion->id])
            ->assertUnprocessable();
    }

    #[Test]
    public function popup_sources_are_validated_and_promotion_data_is_resolved_at_runtime(): void
    {
        $this->actingAsWebAdmin();
        $service = Service::query()->create(['display_name' => 'Visita Laser', 'canonical_name' => 'Visita Laser', 'slug' => 'visita-laser', 'importo_prestazione' => 200, 'is_active' => true]);
        $promotion = Promotion::query()->create(['name' => 'Promo Laser', 'service_id' => $service->id, 'promotional_price' => 150, 'start_at' => now()->subHour(), 'end_at' => now()->addDay(), 'validity_basis' => 'booking_date', 'is_active' => true]);

        $this->save(['is_active' => true, 'source_type' => 'promotion', 'promotion_id' => null])->assertUnprocessable();
        $this->save(['is_active' => true, 'source_type' => 'promotion', 'promotion_id' => $promotion->id, 'event_id' => 12])->assertUnprocessable();
        $this->save(['is_active' => true, 'source_type' => 'promotion', 'promotion_id' => $promotion->id, 'title' => 'Promo attiva', 'body' => null])
            ->assertJsonPath('data.source.type', 'promotion')->assertJsonPath('data.source_is_effectively_available', true);
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data.source.data.name', 'Promo Laser')->assertJsonPath('data.source.data.promotional_price', '150.00')->assertJsonMissingPath('data.source.data.internal_notes');
        $promotion->update(['promotional_price' => 120]);
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data.source.data.promotional_price', '120.00');
        $promotion->delete();
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data', null);
        $this->getJson('/api/v1/admin/site-popup')->assertJsonPath('data.source.data.is_archived', true);
    }

    #[Test]
    public function event_source_is_safe_runtime_content_and_uses_popup_permission_lookups(): void
    {
        $this->actingAsWebAdmin();
        $event = Event::query()->create(['name' => 'Open Day', 'event_type' => 'open_day', 'operational_status' => 'confirmed', 'start_at' => now()->subHour(), 'end_at' => now()->addDay(), 'location_type' => 'online', 'online_url' => 'https://meet.example.test/secret', 'registration_required' => true, 'registration_mode' => 'external_url', 'external_registration_url' => 'https://register.example.test/secret', 'participation_price' => 20, 'internal_notes' => 'Riservato']);

        $this->save(['is_active' => true, 'source_type' => 'event', 'event_id' => $event->id, 'title' => 'Evento attivo', 'body' => null])
            ->assertJsonPath('data.source.type', 'event')->assertJsonPath('data.source_is_effectively_available', true);
        $this->getJson('/api/v1/admin/site-popup/sources')->assertOk()->assertJsonPath('data.events.0.name', 'Open Day');
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data.source.data.location_summary.label', 'Online')->assertJsonMissingPath('data.source.data.online_url')->assertJsonMissingPath('data.source.data.external_registration_url')->assertJsonMissingPath('data.source.data.internal_notes');
        $event->update(['operational_status' => 'cancelled']);
        $this->getJson('/api/v1/public/site/popup')->assertJsonPath('data', null);
    }

    #[Test]
    public function active_popups_require_editorial_content_for_every_source_type(): void
    {
        Storage::fake('public');
        $this->actingAsWebAdmin();
        $service = Service::query()->create(['display_name' => 'Visita', 'canonical_name' => 'Visita', 'slug' => 'visita', 'importo_prestazione' => 100, 'is_active' => true]);
        $promotion = Promotion::query()->create(['name' => 'Promo', 'service_id' => $service->id, 'promotional_price' => 80, 'start_at' => now()->subHour(), 'end_at' => now()->addDay(), 'validity_basis' => 'booking_date', 'is_active' => true]);
        $event = Event::query()->create(['name' => 'Evento', 'event_type' => 'open_day', 'operational_status' => 'confirmed', 'start_at' => now()->addHour(), 'end_at' => now()->addDay(), 'location_type' => 'remedic', 'registration_required' => false, 'registration_mode' => 'none']);

        foreach ([
            ['source_type' => 'manual', 'promotion_id' => null, 'event_id' => null],
            ['source_type' => 'promotion', 'promotion_id' => $promotion->id, 'event_id' => null],
            ['source_type' => 'event', 'promotion_id' => null, 'event_id' => $event->id],
        ] as $source) {
            $this->save([...$source, 'is_active' => true, 'eyebrow' => null, 'title' => null, 'body' => null, 'primary_cta_label' => null, 'primary_cta_target' => null, 'secondary_cta_label' => null, 'secondary_cta_target' => null])->assertUnprocessable();
        }

        $this->save(['source_type' => 'manual', 'is_active' => true, 'title' => 'Solo titolo'])->assertOk();
        $this->save(['title' => null, 'body' => 'Solo testo'])->assertOk();
        $this->save(['body' => null, 'eyebrow' => 'Solo eyebrow'])->assertUnprocessable();
        $this->save(['body' => null, 'eyebrow' => null, 'secondary_cta_label' => 'Secondaria', 'secondary_cta_target' => 'booking'])->assertUnprocessable();
        $this->save(['title' => null, 'body' => null, 'secondary_cta_label' => null, 'secondary_cta_target' => null, 'primary_cta_label' => 'Prenota', 'primary_cta_target' => 'booking'])->assertOk();

        $this->save(['title' => null, 'body' => null, 'primary_cta_label' => null, 'primary_cta_target' => null, 'is_active' => false])->assertOk();
        $this->post('/api/v1/admin/site-popup/image', ['file' => UploadedFile::fake()->image('popup.jpg')])->assertOk();
        $this->save(['is_active' => true])->assertOk();
    }

    #[Test]
    public function popup_source_lookups_expose_prefill_title_image_and_end_at(): void
    {
        $this->actingAsWebAdmin();
        $service = Service::query()->create(['display_name' => 'Visita', 'canonical_name' => 'Visita', 'slug' => 'visita', 'importo_prestazione' => 100, 'is_active' => true]);
        $promotion = Promotion::query()->create(['name' => 'Promo estate', 'service_id' => $service->id, 'promotional_price' => 80, 'start_at' => now()->subHour(), 'end_at' => now()->addDay(), 'validity_basis' => 'booking_date', 'is_active' => true]);
        $event = Event::query()->create(['name' => 'Open day', 'event_type' => 'open_day', 'operational_status' => 'confirmed', 'start_at' => now()->addHour(), 'end_at' => now()->addDays(2), 'location_type' => 'remedic', 'registration_required' => false, 'registration_mode' => 'none']);

        $this->getJson('/api/v1/admin/site-popup/sources')->assertOk()
            ->assertJsonPath('data.promotions.0.image_path', null)
            ->assertJsonPath('data.promotions.0.image_url', null)
            ->assertJsonPath('data.events.0.image_path', null)
            ->assertJsonPath('data.events.0.image_url', null);

        $promotion->update(['image_path' => "promotions/{$promotion->id}/images/promo.jpg"]);
        $event->update(['image_path' => "events/{$event->id}/images/event.jpg"]);

        $this->getJson('/api/v1/admin/site-popup/sources')->assertOk()
            ->assertJsonPath('data.promotions.0.id', $promotion->id)
            ->assertJsonPath('data.promotions.0.name', 'Promo estate')
            ->assertJsonPath('data.promotions.0.image_path', "promotions/{$promotion->id}/images/promo.jpg")
            ->assertJsonPath('data.promotions.0.image_url', fn (string $url): bool => str_contains($url, "promotions/{$promotion->id}/images/promo.jpg"))
            ->assertJsonPath('data.promotions.0.end_at', $promotion->end_at->toIso8601String())
            ->assertJsonPath('data.events.0.id', $event->id)
            ->assertJsonPath('data.events.0.name', 'Open day')
            ->assertJsonPath('data.events.0.image_path', "events/{$event->id}/images/event.jpg")
            ->assertJsonPath('data.events.0.image_url', fn (string $url): bool => str_contains($url, "events/{$event->id}/images/event.jpg"))
            ->assertJsonPath('data.events.0.end_at', $event->end_at->toIso8601String());
    }

    private function save(array $overrides = [])
    {
        return $this->putJson('/api/v1/admin/site-popup', $this->payload($overrides));
    }

    private function payload(array $overrides = []): array
    {
        $popup = SitePopup::query()->first();

        return [...[
            'is_active' => $popup?->is_active ?? false, 'source_type' => $popup?->source_type?->value ?? 'manual', 'promotion_id' => $popup?->promotion_id, 'event_id' => $popup?->event_id, 'start_at' => $popup?->start_at?->toIso8601String(), 'end_at' => $popup?->end_at?->toIso8601String(),
            'eyebrow' => $popup?->eyebrow, 'title' => $popup?->title, 'body' => $popup?->body,
            'primary_cta_label' => $popup?->primary_cta_label, 'primary_cta_target' => $popup?->primary_cta_target,
            'secondary_cta_label' => $popup?->secondary_cta_label, 'secondary_cta_target' => $popup?->secondary_cta_target,
        ], ...$overrides];
    }

    private function actingAsWebAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
