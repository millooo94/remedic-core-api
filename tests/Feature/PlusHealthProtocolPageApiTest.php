<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\User;
use App\Services\PageContentService;
use App\Support\Pages\PageSectionRegistry;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlusHealthProtocolPageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['email' => 'protocol-page-admin@example.com']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function registry_and_initializer_create_the_exact_draft_contract(): void
    {
        $page = $this->createProtocolPage();
        $sections = $page->sections()->ordered()->get()->keyBy('key');

        $this->assertSame(PageSectionRegistry::PLUS_HEALTH_PROTOCOL_SECTION_KEYS, array_keys(PageSectionRegistry::definitions('plus_health_protocol')));
        $this->assertSame(PageSectionRegistry::PLUS_HEALTH_PROTOCOL_SECTION_KEYS, $sections->keys()->all());
        $this->assertSame('protocollo-piu-salute', $page->slug);
        $this->assertNull($page->published_at);
        $this->assertFalse($page->faq_enabled);
        $this->assertCount(4, $sections['promise']->extra_json['values']);
        $this->assertSame(['rapidity', 'professionalism', 'accessibility', 'humanity'], array_column($sections['promise']->extra_json['values'], 'semantic_key'));
        $this->assertSame(['rapidity', 'professionalism', 'accessibility', 'humanity'], array_column($sections['four_pillars']->extra_json['pillars'], 'semantic_key'));
        $this->assertSame('Competenze che lavorano insieme', $sections['four_pillars']->extra_json['pillars'][1]['detail_title']);
        $this->assertSame(['active_listening', 'personalized_care_plan', 'clinical_technology', 'patient_education'], array_column($sections['care_path_overview']->extra_json['items'], 'semantic_key'));
        $this->assertCount(3, $sections['person_first']->extra_json['items']);
        $this->assertSame(0, $page->faqs()->count());
    }

    #[Test]
    public function fixed_semantics_reject_visual_configuration_but_persist_panel_copy_and_bullets(): void
    {
        $page = $this->createProtocolPage();
        $pillars = $page->sections()->where('key', 'four_pillars')->firstOrFail();
        $panelCopy = $pillars->extra_json['pillars'];
        $panelCopy[0]['detail_title'] = 'Un orientamento che non aspetta';
        $panelCopy[0]['bullets'] = ['Prima priorità', 'Seconda priorità'];

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'sections' => [['key' => 'four_pillars', 'title' => $pillars->title, 'data' => ['eyebrow' => 'I QUATTRO PILASTRI', 'body' => $pillars->content, 'pillars' => $panelCopy]]],
        ]))->assertOk();
        $pillars->refresh();
        $this->assertSame('Un orientamento che non aspetta', $pillars->extra_json['pillars'][0]['detail_title']);
        $this->assertSame(['Prima priorità', 'Seconda priorità'], $pillars->extra_json['pillars'][0]['bullets']);

        $invalid = $panelCopy;
        $invalid[0]['wheel_color'] = 'red';
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'sections' => [['key' => 'four_pillars', 'title' => $pillars->title, 'data' => ['body' => $pillars->content, 'pillars' => $invalid]]],
        ]))->assertUnprocessable();

        $reordered = array_reverse($panelCopy);
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'sections' => [['key' => 'four_pillars', 'title' => $pillars->title, 'data' => ['body' => $pillars->content, 'pillars' => $reordered]]],
        ]))->assertUnprocessable();
    }

    #[Test]
    public function media_slots_public_projection_and_semantic_targets_are_safe(): void
    {
        Storage::fake('public');
        $center = Page::query()->create(['internal_key' => 'center', 'title' => 'Centro', 'slug' => 'il-centro', 'template' => 'default', 'is_active' => true, 'published_at' => now()->subMinute()]);
        app(PageContentService::class)->initializeMissingSections($center);
        $page = $this->createProtocolPage(['published_at' => now()->subMinute()]);

        $this->post('/api/v1/admin/pages/media', ['page_id' => $page->id, 'section_key' => 'hero', 'media_slot' => 'image', 'image' => UploadedFile::fake()->image('hero.jpg')])->assertOk();
        $this->post('/api/v1/admin/pages/media', ['page_id' => $page->id, 'section_key' => 'four_pillars', 'media_slot' => 'image', 'image' => UploadedFile::fake()->image('forbidden.jpg')], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->getJson('/api/v1/public/pages/il-centro')->assertOk()->assertJsonPath('data.sections.5.action.href', '/protocollo-piu-salute');
        $page->sections()->where('key', 'patient_education')->update(['is_active' => false]);
        $this->getJson('/api/v1/public/pages/protocollo-piu-salute')
            ->assertOk()
            ->assertJsonMissing(['extra_json'])
            ->assertJsonPath('data.sections.0.key', 'hero')
            ->assertJsonPath('data.sections.1.values.0.semantic_key', 'rapidity')
            ->assertJsonPath('data.sections.2.pillars.1.detail_title', 'Competenze che lavorano insieme')
            ->assertJsonPath('data.sections.3.items.3.semantic_key', 'patient_education')
            ->assertJsonMissing(['wheel_color', 'visual_config', 'autoplay']);
    }

    #[Test]
    public function initializer_preserves_existing_sections_and_rejects_unknown_or_removed_typed_sections(): void
    {
        $page = $this->createProtocolPage();
        $hero = $page->sections()->where('key', 'hero')->firstOrFail();
        $heroId = $hero->id;
        $hero->update(['title' => 'Copy editoriale']);
        app(PageContentService::class)->initializeMissingSections($page);
        $this->assertSame($heroId, $hero->fresh()->id);
        $this->assertSame('Copy editoriale', $hero->fresh()->title);

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'sections' => [['key' => 'arbitrary', 'title' => 'No', 'data' => ['body' => 'No']]],
        ]))->assertUnprocessable();
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'removed_section_keys' => ['hero'],
        ]))->assertUnprocessable();
    }

    /** @param array<string, mixed> $overrides */
    private function createProtocolPage(array $overrides = []): Page
    {
        $response = $this->postJson('/api/v1/admin/pages', [
            'internal_key' => 'plus_health_protocol', 'title' => 'Protocollo Più Salute', 'slug' => 'protocollo-piu-salute',
            'template' => 'default', 'faq_enabled' => false, 'is_active' => true, 'published_at' => null, ...$overrides,
        ]);
        $response->assertSuccessful();

        return Page::query()->findOrFail((int) $response->json('id'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(Page $page, array $overrides = []): array
    {
        return ['title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true, 'published_at' => optional($page->published_at)?->toIso8601String(), ...$overrides];
    }
}
