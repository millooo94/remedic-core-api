<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PageContentService;
use App\Support\Pages\PageSectionRegistry;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactPageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function contact_is_a_closed_three_section_draft_without_faqs(): void
    {
        $page = $this->createContact();

        $this->assertSame(PageSectionRegistry::CONTACT_SECTION_KEYS, array_keys(PageSectionRegistry::definitions('contact')));
        $this->assertSame(PageSectionRegistry::CONTACT_SECTION_KEYS, $page->sections()->ordered()->pluck('key')->all());
        $this->assertSame('contatti', $page->slug);
        $this->assertNull($page->published_at);
        $this->assertSame(0, $page->faqs()->count());

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['faqs' => [[
            'question' => 'FAQ non consentita', 'answer' => 'La pagina Contatti non la supporta.',
        ]]]))->assertUnprocessable();
    }

    #[Test]
    public function location_persists_only_intro_and_rejects_center_fields_while_media_is_hero_only(): void
    {
        $page = $this->createContact();
        $location = $page->sections()->where('key', 'location_and_contacts')->firstOrFail();
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['sections' => [[
            'key' => 'location_and_contacts', 'title' => 'Sede', 'data' => ['intro' => 'Intro aggiornata'],
        ]]]))->assertOk();
        $location->refresh();
        $this->assertSame('Intro aggiornata', $location->content);
        $this->assertSame([], $location->extra_json ?? []);

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['sections' => [[
            'key' => 'location_and_contacts', 'title' => 'Sede', 'data' => ['intro' => 'No', 'phone' => '+39 02 1234 5678'],
        ]]]))->assertUnprocessable();
        $this->post('/api/v1/admin/pages/media', ['page_id' => $page->id, 'section_key' => 'location_and_contacts', 'media_slot' => 'image', 'image' => UploadedFile::fake()->image('no.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    #[Test]
    public function public_location_derives_only_safe_center_data_without_touching_the_page(): void
    {
        $settings = SiteSetting::ensureSingleton();
        $settings->update(['clinic_address' => 'Via reale 1', 'clinic_city' => 'Catania', 'clinic_phone' => '+39 095 111111', 'clinic_email' => 'info@remedic.it', 'google_maps_url' => 'https://maps.example.test/reale', 'latitude' => 37.5, 'longitude' => 15.0, 'opening_hours' => ['version' => 1, 'days' => []], 'privacy_email' => 'privacy@example.test', 'parking_address' => 'Parcheggio reale']);
        $page = $this->createContact(['published_at' => now()->subMinute()]);

        $this->getJson('/api/v1/public/pages/contatti')
            ->assertOk()
            ->assertJsonPath('data.sections.1.data.intro', 'Consulta i recapiti e gli orari del centro, oppure scegli l’azione più utile per te.')
            ->assertJsonPath('data.sections.1.data.action.type', 'contact')
            ->assertJsonPath('data.sections.1.data.center.address.formatted_address', 'Via reale 1')
            ->assertJsonPath('data.sections.1.data.center.phone', '+39 095 111111')
            ->assertJsonPath('data.sections.1.data.center.directions_href', 'https://maps.example.test/reale')
            ->assertJsonPath('data.sections.2.actions.0', 'booking')
            ->assertJsonMissing(['privacy_email', 'extra_json']);
        $updatedAt = $page->updated_at;
        $settings->update(['clinic_phone' => '+39 095 222222']);
        $this->getJson('/api/v1/public/pages/contatti')->assertOk()->assertJsonPath('data.sections.1.data.center.phone', '+39 095 222222');
        $this->assertTrue($page->fresh()->updated_at->equalTo($updatedAt));
    }

    /** @param array<string, mixed> $overrides */
    private function createContact(array $overrides = []): Page
    {
        $existing = Page::query()->where('slug', 'contatti')->first();
        if ($existing !== null) {
            $existing->update(['internal_key' => 'contact', 'title' => 'Contatti', 'is_active' => true, 'published_at' => $overrides['published_at'] ?? null, 'faq_enabled' => false]);
            app(PageContentService::class)->initializeMissingSections($existing);

            return $existing->fresh();
        }
        $response = $this->postJson('/api/v1/admin/pages', ['internal_key' => 'contact', 'title' => 'Contatti', 'slug' => 'contatti', 'template' => 'default', 'faq_enabled' => false, 'is_active' => true, 'published_at' => null, ...$overrides]);
        $response->assertSuccessful();

        return Page::query()->findOrFail((int) $response->json('id'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(Page $page, array $overrides = []): array
    {
        return ['title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true, 'published_at' => optional($page->published_at)?->toIso8601String(), ...$overrides];
    }
}
