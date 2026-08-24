<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\Section;
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

class CenterPageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['email' => 'center-page-admin@example.com']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function center_registry_is_closed_and_has_exactly_the_approved_ordered_sections(): void
    {
        $definitions = PageSectionRegistry::definitions(PageSectionRegistry::CENTER_INTERNAL_KEY);

        $this->assertSame(PageSectionRegistry::CENTER_SECTION_KEYS, array_keys($definitions));
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], array_column($definitions, 'default_sort_order'));
        $this->assertSame('center-hero', $definitions['hero']['editor']);
        $this->assertSame('center-intro', $definitions['intro']['editor']);
        $this->assertSame('center-image-text', $definitions['coordinated_care']['editor']);
        $this->assertSame('center-image-text', $definitions['continuity']['editor']);
        $this->assertSame('center-linked-image-text', $definitions['why_remedic']['editor']);
        $this->assertSame('center-linked-image-text', $definitions['plus_health_protocol']['editor']);
        $this->assertSame('center-orientation-cta', $definitions['orientation_cta']['editor']);
    }

    #[Test]
    public function creating_center_from_the_normal_page_api_initializes_only_the_seven_draft_sections(): void
    {
        $center = $this->createCenter();

        $this->assertSame('center', $center->internal_key);
        $this->assertSame('il-centro', $center->slug);
        $this->assertNull($center->published_at);
        $this->assertTrue($center->is_active);
        $this->assertFalse($center->faq_enabled);
        $this->assertSame(PageSectionRegistry::CENTER_SECTION_KEYS, $center->sections()->ordered()->pluck('key')->all());
        $this->assertSame('IL CENTRO', $center->sections()->where('key', 'hero')->value('extra_json')['eyebrow']);
        $this->assertSame('Remedic, vicino alle persone', $center->sections()->where('key', 'intro')->value('title'));
        $this->assertSame('why_choose_us', $center->sections()->where('key', 'why_remedic')->value('extra_json')['target_internal_key']);
        $this->assertSame(['booking', 'contact'], $center->sections()->where('key', 'orientation_cta')->value('extra_json')['actions']);
        $this->assertSame(0, $center->faqs()->count());
    }

    #[Test]
    public function center_typed_updates_preserve_identity_reject_arbitrary_keys_and_never_overwrite_missing_content(): void
    {
        $center = $this->createCenter();
        $hero = $center->sections()->where('key', 'hero')->firstOrFail();
        $intro = $center->sections()->where('key', 'intro')->firstOrFail();
        $heroCreatedAt = $hero->created_at->toISOString();

        $this->putJson("/api/v1/admin/pages/{$center->id}", $this->pagePayload($center, [
            'sections' => [[
                'key' => 'hero',
                'title' => 'Titolo aggiornato',
                'sort_order' => 3,
                'is_active' => false,
                'data' => ['eyebrow' => 'NUOVO', 'body' => 'Testo aggiornato', 'image_alt' => 'Alt editoriale'],
            ]],
        ]))->assertOk();

        $hero->refresh();
        $this->assertSame($heroCreatedAt, $hero->created_at->toISOString());
        $this->assertSame('Titolo aggiornato', $hero->title);
        $this->assertSame('Testo aggiornato', $hero->content);
        $this->assertFalse($hero->is_active);
        $this->assertSame('Alt editoriale', $hero->extra_json['image_alt']);
        $this->assertSame($intro->id, $center->sections()->where('key', 'intro')->value('id'));

        $this->putJson("/api/v1/admin/pages/{$center->id}", $this->pagePayload($center, [
            'sections' => [['key' => 'unexpected', 'title' => 'No', 'data' => ['body' => 'No']]],
        ]))->assertUnprocessable();
        $this->putJson("/api/v1/admin/pages/{$center->id}", $this->pagePayload($center, [
            'sections' => [['key' => 'hero', 'title' => 'No', 'extra_json' => ['layout' => 'dark']]],
        ]))->assertUnprocessable();

        $unknown = $center->sections()->create(['key' => 'historical_unknown', 'title' => 'Storica', 'sort_order' => 99, 'is_active' => true]);
        $this->putJson("/api/v1/admin/pages/{$center->id}", $this->pagePayload($center))->assertOk();
        $this->assertDatabaseHas('sections', ['id' => $unknown->id, 'key' => 'historical_unknown']);
    }

    #[Test]
    public function center_section_media_is_slot_scoped_replaced_safely_and_removed_safely(): void
    {
        Storage::fake('public');
        $center = $this->createCenter();

        $first = $this->post('/api/v1/admin/pages/media', [
            'page_id' => $center->id,
            'section_key' => 'hero',
            'media_slot' => 'image',
            'image' => UploadedFile::fake()->image('hero-one.jpg', 1200, 800),
        ])->assertOk();
        $firstPath = (string) $first->json('image_path');
        Storage::disk('public')->assertExists($firstPath);
        $first->assertJsonPath('section_key', 'hero')->assertJsonPath('image_alt', null);

        $second = $this->post('/api/v1/admin/pages/media', [
            'page_id' => $center->id,
            'section_key' => 'hero',
            'media_slot' => 'image',
            'image' => UploadedFile::fake()->image('hero-two.webp', 1200, 800),
        ])->assertOk();
        $secondPath = (string) $second->json('image_path');
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->post('/api/v1/admin/pages/media', [
            'page_id' => $center->id,
            'section_key' => 'intro',
            'media_slot' => 'image',
            'image' => UploadedFile::fake()->image('not-allowed.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->deleteJson("/api/v1/admin/pages/{$center->id}/sections/hero/media")
            ->assertOk()
            ->assertJsonPath('image_path', null);
        Storage::disk('public')->assertMissing($secondPath);
    }

    #[Test]
    public function center_public_contract_is_typed_ordered_and_resolves_only_published_semantic_targets(): void
    {
        $center = $this->createCenter(['published_at' => now()->subMinute()]);
        $center->sections()->where('key', 'continuity')->update(['is_active' => false]);
        $center->sections()->create(['key' => 'historical_unknown', 'sort_order' => 99, 'is_active' => true]);

        $this->getJson('/api/v1/public/pages/il-centro')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'hero')
            ->assertJsonMissing(['extra_json'])
            ->assertJsonPath('data.sections.3.key', 'why_remedic')
            ->assertJsonPath('data.sections.3.action.href', null)
            ->assertJsonPath('data.sections.4.action.href', null)
            ->assertJsonPath('data.sections.5.actions', ['booking', 'contact']);

        Page::query()->create(['internal_key' => 'why_choose_us', 'title' => 'Perché sceglierci', 'slug' => 'perche-sceglierci', 'template' => 'default', 'is_active' => true, 'published_at' => now()->subMinute()]);
        Page::query()->create(['internal_key' => 'plus_health_protocol', 'title' => 'Protocollo', 'slug' => 'protocollo-piu-salute', 'template' => 'default', 'is_active' => true, 'published_at' => now()->addDay()]);

        $this->getJson('/api/v1/public/pages/il-centro')
            ->assertOk()
            ->assertJsonPath('data.sections.3.action.href', '/perche-sceglierci')
            ->assertJsonPath('data.sections.4.action.href', null);
    }

    #[Test]
    public function center_creation_does_not_modify_legacy_chi_siamo_or_create_faqs(): void
    {
        $legacy = Page::query()->where('slug', 'chi-siamo')->with(['sections', 'faqs'])->firstOrFail();
        $snapshot = [
            'id' => $legacy->id,
            'slug' => $legacy->slug,
            'title' => $legacy->title,
            'seo_title' => $legacy->seo_title,
            'published_at' => optional($legacy->published_at)?->toISOString(),
            'updated_at' => optional($legacy->updated_at)?->toISOString(),
            'sections' => $legacy->sections->map(fn (Section $section) => [$section->id, $section->key, $section->updated_at?->toISOString()])->all(),
        ];

        $this->createCenter();
        $legacy->refresh()->load('sections');

        $this->assertSame($snapshot['id'], $legacy->id);
        $this->assertSame($snapshot['slug'], $legacy->slug);
        $this->assertSame($snapshot['title'], $legacy->title);
        $this->assertSame($snapshot['seo_title'], $legacy->seo_title);
        $this->assertSame($snapshot['published_at'], optional($legacy->published_at)?->toISOString());
        $this->assertSame($snapshot['updated_at'], optional($legacy->updated_at)?->toISOString());
        $this->assertSame($snapshot['sections'], $legacy->sections->map(fn (Section $section) => [$section->id, $section->key, $section->updated_at?->toISOString()])->all());
        $this->assertSame(0, Page::query()->where('internal_key', 'center')->firstOrFail()->faqs()->count());
    }

    /** @param array<string, mixed> $overrides */
    private function createCenter(array $overrides = []): Page
    {
        $response = $this->postJson('/api/v1/admin/pages', [
            'internal_key' => 'center',
            'title' => 'Il centro',
            'slug' => 'il-centro',
            'template' => 'default',
            'faq_enabled' => false,
            'is_active' => true,
            'published_at' => null,
            ...$overrides,
        ]);
        $response->assertSuccessful();

        return Page::query()->with('sections')->findOrFail((int) $response->json('id'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function pagePayload(Page $page, array $overrides = []): array
    {
        return [
            'title' => $page->title,
            'slug' => $page->slug,
            'template' => $page->template?->value ?? $page->template,
            'faq_enabled' => false,
            'is_active' => (bool) $page->is_active,
            'published_at' => optional($page->published_at)?->toIso8601String(),
            ...$overrides,
        ];
    }
}
