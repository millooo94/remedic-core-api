<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\User;
use App\Support\Pages\PageSectionRegistry;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WhyChooseUsPageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['email' => 'why-page-admin@example.com']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function registry_has_exactly_the_eight_approved_why_choose_us_sections(): void
    {
        $definitions = PageSectionRegistry::definitions('why_choose_us');
        $this->assertSame(PageSectionRegistry::WHY_CHOOSE_US_SECTION_KEYS, array_keys($definitions));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], array_column($definitions, 'default_sort_order'));
        $this->assertSame('why-model-overview', $definitions['model_overview']['editor']);
        $this->assertSame('why-patient-experiences', $definitions['patient_experiences']['editor']);
    }

    #[Test]
    public function create_initializes_a_draft_with_repeaters_and_no_faqs(): void
    {
        $page = $this->createWhyPage();
        $sections = $page->sections()->ordered()->get()->keyBy('key');

        $this->assertSame('perche-sceglierci', $page->slug);
        $this->assertFalse($page->faq_enabled);
        $this->assertSame(PageSectionRegistry::WHY_CHOOSE_US_SECTION_KEYS, $sections->keys()->all());
        $this->assertCount(4, $sections['model_overview']->extra_json['items']);
        $this->assertCount(3, $sections['three_reasons']->extra_json['items']);
        $this->assertSame(['network', 'microscope', 'heart'], array_column($sections['three_reasons']->extra_json['items'], 'icon_key'));
        $this->assertSame(['eyebrow' => 'LA VOCE DEI PAZIENTI'], $sections['patient_experiences']->extra_json);
    }

    #[Test]
    public function typed_repeaters_preserve_section_identity_and_reject_invalid_data(): void
    {
        $page = $this->createWhyPage();
        $reasons = $page->sections()->where('key', 'three_reasons')->firstOrFail();
        $id = $reasons->id;
        $items = $reasons->extra_json['items'];
        $items[] = ['icon_key' => 'heart', 'title' => 'Nuovo motivo', 'description' => 'Descrizione'];

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'sections' => [[
                'key' => 'three_reasons', 'title' => $reasons->title, 'sort_order' => 2, 'is_active' => false,
                'data' => ['body' => $reasons->content, 'items' => $items],
            ]],
        ]))->assertOk();
        $reasons->refresh();
        $this->assertSame($id, $reasons->id);
        $this->assertFalse($reasons->is_active);
        $this->assertCount(4, $reasons->extra_json['items']);

        $invalid = $items;
        $invalid[0]['icon_key'] = 'url';
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'sections' => [['key' => 'three_reasons', 'title' => 'No', 'data' => ['body' => 'No', 'items' => $invalid]]],
        ]))->assertUnprocessable();
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->payload($page, [
            'sections' => [['key' => 'unexpected', 'title' => 'No', 'data' => ['body' => 'No']]],
        ]))->assertUnprocessable();
    }

    #[Test]
    public function media_and_public_projection_are_typed_and_protocol_target_is_safe(): void
    {
        Storage::fake('public');
        $page = $this->createWhyPage();
        $upload = $this->post('/api/v1/admin/pages/media', [
            'page_id' => $page->id, 'section_key' => 'integrated_workflow', 'media_slot' => 'image',
            'image' => UploadedFile::fake()->image('workflow.jpg'),
        ])->assertOk();
        Storage::disk('public')->assertExists($upload->json('image_path'));
        $this->post('/api/v1/admin/pages/media', [
            'page_id' => $page->id, 'section_key' => 'model_overview', 'media_slot' => 'image',
            'image' => UploadedFile::fake()->image('forbidden.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $page->sections()->where('key', 'continuity')->update(['is_active' => false]);
        $this->getJson('/api/v1/public/pages/perche-sceglierci')
            ->assertOk()
            ->assertJsonMissing(['extra_json'])
            ->assertJsonPath('data.sections.5.key', 'plus_health_protocol_cta')
            ->assertJsonPath('data.sections.5.action.href', null)
            ->assertJsonPath('data.sections.4.reviews', []);

        Page::query()->create(['internal_key' => 'plus_health_protocol', 'title' => 'Protocollo', 'slug' => 'protocollo-piu-salute', 'template' => 'default', 'is_active' => true]);
        $this->getJson('/api/v1/public/pages/perche-sceglierci')->assertOk()->assertJsonPath('data.sections.5.action.href', '/protocollo-piu-salute');
    }

    /** @param array<string, mixed> $overrides */
    private function createWhyPage(array $overrides = []): Page
    {
        $response = $this->postJson('/api/v1/admin/pages', [
            'internal_key' => 'why_choose_us', 'title' => 'Perché scegliere Remedic', 'slug' => 'perche-sceglierci',
            'template' => 'default', 'faq_enabled' => false, 'is_active' => true, ...$overrides,
        ]);
        $response->assertSuccessful();

        return Page::query()->findOrFail((int) $response->json('id'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(Page $page, array $overrides = []): array
    {
        return ['title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true, ...$overrides];
    }
}
