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
    public function contact_is_a_closed_typed_draft_with_its_final_orientation_section_without_faqs(): void
    {
        $page = $this->createContact();

        $this->assertSame(PageSectionRegistry::CONTACT_SECTION_KEYS, array_keys(PageSectionRegistry::definitions('contact')));
        $this->assertSame(PageSectionRegistry::CONTACT_SECTION_KEYS, $page->sections()->ordered()->pluck('key')->all());
        $this->assertSame('Contatti', $page->sections()->where('key', 'hero')->value('internal_title'));
        $this->assertSame('contatti', $page->slug);
        $this->assertSame(0, $page->faqs()->count());

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['faqs' => [[
            'question' => 'FAQ non consentita', 'answer' => 'La pagina Contatti non la supporta.',
        ]]]))->assertUnprocessable();
    }

    #[Test]
    public function location_persists_its_copy_and_controlled_cta_while_rejecting_center_fields_and_media(): void
    {
        $page = $this->createContact();
        $location = $page->sections()->where('key', 'location_and_contacts')->firstOrFail();
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['sections' => [[
            'key' => 'location_and_contacts', 'title' => 'Sede', 'internal_title' => 'Sezione contatti', 'data' => ['intro' => 'Intro aggiornata', 'cta_label' => 'Scrivici', 'cta_target' => 'email'],
        ]]]))->assertOk();
        $location->refresh();
        $this->assertSame('Intro aggiornata', $location->content);
        $this->assertSame('Sezione contatti', $location->internal_title);
        $this->assertSame(['cta_label' => 'Scrivici', 'cta_target' => 'email'], $location->extra_json ?? []);

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['sections' => [[
            'key' => 'location_and_contacts', 'title' => 'Sede', 'data' => ['intro' => 'No', 'phone' => '+39 02 1234 5678'],
        ]]]))->assertUnprocessable();
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['sections' => [[
            'key' => 'location_and_contacts', 'title' => 'Sede', 'data' => ['intro' => 'No', 'cta_label' => 'Link', 'cta_target' => 'external_url'],
        ]]]))->assertUnprocessable()->assertJsonValidationErrors('sections.0.data.cta_target');
        $this->post('/api/v1/admin/pages/media', ['page_id' => $page->id, 'section_key' => 'location_and_contacts', 'media_slot' => 'image', 'image' => UploadedFile::fake()->image('no.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    #[Test]
    public function public_location_derives_only_safe_center_data_without_touching_the_page(): void
    {
        $settings = SiteSetting::ensureSingleton();
        $settings->update(['clinic_address' => 'Via reale 1', 'clinic_city' => 'Catania', 'clinic_phone' => '+39 095 111111', 'clinic_email' => 'info@remedic.it', 'google_maps_url' => 'https://maps.example.test/reale', 'latitude' => 37.5, 'longitude' => 15.0, 'opening_hours' => ['version' => 1, 'days' => []], 'privacy_email' => 'privacy@example.test', 'parking_address' => 'Parcheggio reale']);
        $page = $this->createContact();

        $this->getJson('/api/v1/public/pages/contatti')
            ->assertOk()
            ->assertJsonPath('data.sections.1.data.intro', 'Consulta i recapiti e gli orari del centro, oppure scegli l’azione più utile per te.')
            ->assertJsonPath('data.sections.1.data.cta.label', 'Contattaci')
            ->assertJsonPath('data.sections.1.data.cta.href', '/contatti')
            ->assertJsonPath('data.sections.1.data.action.type', 'contact')
            ->assertJsonPath('data.sections.1.data.center.address.formatted_address', 'Via reale 1')
            ->assertJsonPath('data.sections.1.data.center.phone', '+39 095 111111')
            ->assertJsonPath('data.sections.1.data.center.directions_href', 'https://maps.example.test/reale')
            ->assertJsonMissing(['privacy_email', 'extra_json']);
        $updatedAt = $page->updated_at;
        $settings->update(['clinic_phone' => '+39 095 222222']);
        $this->getJson('/api/v1/public/pages/contatti')->assertOk()->assertJsonPath('data.sections.1.data.center.phone', '+39 095 222222');
        $this->assertTrue($page->fresh()->updated_at->equalTo($updatedAt));
    }

    #[Test]
    public function final_orientation_persists_its_two_controlled_ctas_and_projects_them_safely(): void
    {
        $page = $this->createContact();

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, ['sections' => [[
            'key' => 'orientation_cta',
            'title' => 'Cerchi una visita o un esame?',
            'data' => [
                'body' => 'Ti aiutiamo a scegliere il percorso più adatto.',
                'primary_cta_label' => 'Prenota ora',
                'primary_cta_target' => 'booking',
                'secondary_cta_label' => 'Contattaci',
                'secondary_cta_target' => 'contact',
            ],
        ]]]))->assertOk();

        $this->getJson('/api/v1/public/pages/contatti')
            ->assertOk()
            ->assertJsonPath('data.sections.2.title', 'Cerchi una visita o un esame?')
            ->assertJsonPath('data.sections.2.body', 'Ti aiutiamo a scegliere il percorso più adatto.')
            ->assertJsonPath('data.sections.2.actions', ['booking', 'contact'])
            ->assertJsonPath('data.sections.2.primary_cta.label', 'Prenota ora')
            ->assertJsonPath('data.sections.2.secondary_cta.label', 'Contattaci');
    }

    #[Test]
    public function existing_orientation_cta_is_preserved_and_its_missing_cta_defaults_are_moved_to_location(): void
    {
        $page = $this->createContact();
        $page->sections()->where('key', 'orientation_cta')->update(['title' => 'Storico', 'content' => 'Contenuto storico', 'extra_json' => ['cta_label' => 'Parla con noi', 'cta_target' => 'phone'], 'sort_order' => 2]);
        $location = $page->sections()->where('key', 'location_and_contacts')->firstOrFail();
        $location->update(['extra_json' => []]);

        app(PageContentService::class)->initializeMissingSections($page);

        $this->assertNotNull($page->fresh()->sections()->where('key', 'orientation_cta')->where('title', 'Storico')->first());
        $this->assertSame(['cta_label' => 'Parla con noi', 'cta_target' => 'phone'], $location->fresh()->extra_json);
    }

    /** @param array<string, mixed> $overrides */
    private function createContact(array $overrides = []): Page
    {
        $existing = Page::query()->where('slug', 'contatti')->first();
        if ($existing !== null) {
            $existing->update(['internal_key' => 'contact', 'title' => 'Contatti', 'is_active' => true, 'faq_enabled' => false]);
            app(PageContentService::class)->initializeMissingSections($existing);

            return $existing->fresh();
        }
        $response = $this->postJson('/api/v1/admin/pages', ['internal_key' => 'contact', 'title' => 'Contatti', 'slug' => 'contatti', 'template' => 'default', 'faq_enabled' => false, 'is_active' => true, ...$overrides]);
        $response->assertSuccessful();

        return Page::query()->findOrFail((int) $response->json('id'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(Page $page, array $overrides = []): array
    {
        return ['title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true, ...$overrides];
    }
}
