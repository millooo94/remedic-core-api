<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\LegalDocumentInitializer;
use App\Support\Pages\LegalDocumentRegistry;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LegalDocumentsApiTest extends TestCase
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
    public function initializer_reuses_clear_legacy_sections_creates_terms_and_never_overwrites_later_editorial_copy(): void
    {
        $privacy = $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $cookie = $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $legacyHero = $privacy->sections()->where('key', 'hero')->firstOrFail();

        $result = app(LegalDocumentInitializer::class)->initialize();

        $this->assertSame($privacy->id, $result['privacy']->id);
        $this->assertSame($cookie->id, $result['cookie']->id);
        $this->assertSame($legacyHero->id, $privacy->sections()->where('key', 'legal_hero')->value('id'));
        $this->assertSame(LegalDocumentRegistry::sectionKeys('privacy'), $privacy->sections()->ordered()->pluck('key')->all());
        $this->assertSame(LegalDocumentRegistry::sectionKeys('cookie-policy'), $cookie->sections()->ordered()->pluck('key')->all());
        $this->assertCount(14, $privacy->sections()->get());
        $this->assertCount(10, $cookie->sections()->get());
        $this->assertCount(16, $result['terms']->sections()->get());
        $this->assertSame('termini-di-servizio', $result['terms']->slug);
        $this->assertSame('/termini-di-servizio', $result['terms']->canonical_url);
        $this->assertNull($result['terms']->published_at);
        $this->assertSame(0, $result['terms']->faqs()->count());

        $scope = $result['terms']->sections()->where('key', 'scope')->firstOrFail();
        $scope->update(['extra_json' => ['blocks' => [['type' => 'paragraph', 'parts' => [['type' => 'text', 'text' => 'Copy editoriale futuro']]]]]]);
        app(LegalDocumentInitializer::class)->initialize();
        $this->assertSame('Copy editoriale futuro', $scope->fresh()->extra_json['blocks'][0]['text']);
    }

    #[Test]
    public function public_legal_projection_exposes_only_active_safe_structured_content_and_runtime_center_values(): void
    {
        $privacy = $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        app(LegalDocumentInitializer::class)->initialize();
        SiteSetting::ensureSingleton()->update(['clinic_email' => 'info@remedic.test', 'privacy_email' => 'privacy@remedic.test', 'clinic_phone' => '+39 095 111111', 'clinic_address' => 'Via Roma 1']);
        $privacy->sections()->where('key', 'minors')->update(['is_active' => false]);

        $this->getJson('/api/v1/public/pages/privacy')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'legal_hero')
            ->assertJsonPath('data.sections.2.blocks.0.parts.1.value', 'privacy@remedic.test')
            ->assertJsonPath('data.sections.4.blocks.1.parts.1.href', '/cookie-policy')
            ->assertJsonPath('data.toc.0.href', '#scope')
            ->assertJsonMissing(['extra_json', 'minors']);
        $this->getJson('/api/v1/public/pages/termini-di-servizio')->assertOk();
        Page::query()->where('internal_key', Page::TERMS_OF_SERVICE_INTERNAL_KEY)->update(['is_active' => false]);
        $this->getJson('/api/v1/public/pages/termini-di-servizio')->assertNotFound();
    }

    #[Test]
    public function legal_placeholder_links_resolve_owner_contacts_without_exposing_placeholder_tokens(): void
    {
        $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $page = app(LegalDocumentInitializer::class)->initialize()['privacy'];
        SiteSetting::ensureSingleton()->update(['privacy_email' => 'titolare@remedic.test', 'clinic_phone' => '+39 095 222222']);

        $this->putJson("/api/v1/admin/pages/{$page->id}", [
            'title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true,
            'sections' => [
                [
                    'key' => 'scope',
                    'title' => 'Ambito',
                    'data' => [
                        'blocks' => [[
                            'type' => 'paragraph',
                            'text' => 'Scrivi a {{1}} o chiama {{2}}.',
                            'links' => [['placeholder' => 1, 'target' => 'owner_email'], ['placeholder' => 2, 'target' => 'owner_phone']],
                        ]],
                    ],
                ],
            ],
        ])->assertOk();

        $this->getJson('/api/v1/public/pages/privacy')
            ->assertOk()
            ->assertJsonPath('data.sections.1.blocks.0.parts.1.field', 'email')
            ->assertJsonPath('data.sections.1.blocks.0.parts.1.value', 'titolare@remedic.test')
            ->assertJsonPath('data.sections.1.blocks.0.parts.3.field', 'phone')
            ->assertJsonPath('data.sections.1.blocks.0.parts.3.value', '+39 095 222222')
            ->assertJsonMissing(['{{1}}', '{{2}}']);
    }

    #[Test]
    public function legal_section_reorder_is_atomic_page_scoped_and_preserves_section_identity(): void
    {
        $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $page = app(LegalDocumentInitializer::class)->initialize()['privacy'];
        $before = $page->sections()->ordered()->get()->keyBy('key');
        $keys = $before->keys()->all();
        [$keys[1], $keys[2]] = [$keys[2], $keys[1]];

        $this->putJson("/api/v1/admin/pages/{$page->id}/sections/reorder", ['section_keys' => $keys])
            ->assertOk();

        $this->assertSame($keys, $page->fresh()->sections()->ordered()->pluck('key')->all());
        foreach ($before as $key => $section) {
            $this->assertSame($section->internal_title, $page->sections()->where('key', $key)->value('internal_title'));
            $this->assertSame($section->title, $page->sections()->where('key', $key)->value('title'));
            $this->assertSame($section->content, $page->sections()->where('key', $key)->value('content'));
            $this->assertSame($section->extra_json, $page->sections()->where('key', $key)->value('extra_json'));
        }
        $this->getJson('/api/v1/public/pages/privacy')
            ->assertOk()
            ->assertJsonPath('data.sections.1.key', $keys[1])
            ->assertJsonPath('data.toc.0.href', '#'.$keys[1]);

        $foreign = Page::query()->create(['internal_key' => 'other-page', 'title' => 'Altra pagina', 'slug' => 'other-page', 'template' => 'default']);
        $foreign->sections()->create(['key' => 'foreign-section', 'title' => 'Estranea', 'sort_order' => 0, 'is_active' => true]);
        $invalid = $keys;
        $invalid[1] = 'foreign-section';
        $this->putJson("/api/v1/admin/pages/{$page->id}/sections/reorder", ['section_keys' => $invalid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('section_keys');
        $this->assertSame($keys, $page->fresh()->sections()->ordered()->pluck('key')->all());

        foreach ([
            array_slice($keys, 1),
            [...array_slice($keys, 0, -1), $keys[0]],
            [...array_slice($keys, 0, 1), 'missing-section', ...array_slice($keys, 2)],
        ] as $invalidKeys) {
            $this->putJson("/api/v1/admin/pages/{$page->id}/sections/reorder", ['section_keys' => $invalidKeys])
                ->assertUnprocessable();
            $this->assertSame($keys, $page->fresh()->sections()->ordered()->pluck('key')->all());
        }
    }

    #[Test]
    public function legal_editor_accepts_only_controlled_references(): void
    {
        $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $page = app(LegalDocumentInitializer::class)->initialize()['privacy'];

        $this->putJson("/api/v1/admin/pages/{$page->id}", [
            'title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true,
            'sections' => [['key' => 'scope', 'title' => 'Ambito', 'data' => ['blocks' => [['type' => 'paragraph', 'text' => '{{1}}', 'links' => [['placeholder' => 1, 'target' => 'arbitrary']]]]]]],
        ])->assertUnprocessable();
    }

    #[Test]
    public function controller_contacts_and_terms_contacts_keep_exactly_two_fixed_placeholder_links(): void
    {
        $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $documents = app(LegalDocumentInitializer::class)->initialize();

        foreach ([[$documents['privacy'], 'controller_contacts'], [$documents['terms'], 'contacts']] as [$page, $key]) {
            $page->sections()->where('key', $key)->firstOrFail()->update(['extra_json' => ['blocks' => [[
                'type' => 'paragraph', 'text' => 'Contatto {{1}}, {{2}} o {{3}}.',
                'links' => [
                    ['placeholder' => 1, 'target' => 'owner_email'],
                    ['placeholder' => 2, 'target' => 'owner_phone'],
                    ['placeholder' => 3, 'target' => 'center_address'],
                ],
            ]]]]);
        }

        app(LegalDocumentInitializer::class)->initialize();

        foreach ([[$documents['privacy'], 'controller_contacts'], [$documents['terms'], 'contacts']] as [$page, $key]) {
            $blocks = $page->fresh()->sections()->where('key', $key)->value('extra_json')['blocks'];
            $paragraph = collect($blocks)->first(fn (array $block): bool => ($block['type'] ?? null) === 'paragraph' && isset($block['text']));
            preg_match_all('/\{\{([1-9][0-9]*)\}\}/', (string) $paragraph['text'], $matches);

            $this->assertSame(['1', '2'], $matches[1]);
            $this->assertCount(2, $paragraph['links']);
            $this->assertSame(['owner_email', 'owner_phone'], array_column($paragraph['links'], 'target'));
        }
    }

    #[Test]
    public function legal_updates_touch_the_page_and_project_only_safe_automatic_structured_content(): void
    {
        Carbon::setTestNow('2026-09-05 08:00:00');
        try {
            $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
            $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
            $page = app(LegalDocumentInitializer::class)->initialize()['privacy'];

            Carbon::setTestNow('2026-09-06 09:30:00');
            $this->putJson("/api/v1/admin/pages/{$page->id}", [
                'title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true,
                'sections' => [[
                    'key' => 'scope', 'title' => 'Ambito dell’informativa', 'data' => ['blocks' => [
                        ['type' => 'paragraph', 'text' => 'Testo normale {{1}}', 'links' => [['placeholder' => 1, 'target' => 'privacy_guarantor', 'label' => 'in evidenza']]],
                        ['type' => 'bullet_list', 'intro' => 'Prima della lista.', 'items' => ['Voce uno'], 'outro' => 'Dopo la lista.'],
                    ]],
                ]],
            ])->assertOk();

            $this->getJson('/api/v1/public/pages/privacy')
                ->assertOk()
                ->assertJsonPath('data.sections.0.data.last_updated_at', '2026-09-06')
                ->assertJsonMissing(['last_updated_on'])
                ->assertJsonPath('data.sections.1.blocks.0.parts.1.type', 'external_reference')
                ->assertJsonPath('data.sections.1.blocks.0.parts.1.href', 'https://www.garanteprivacy.it/')
                ->assertJsonPath('data.sections.1.blocks.1.intro', 'Prima della lista.')
                ->assertJsonPath('data.sections.1.blocks.1.outro', 'Dopo la lista.');

            $this->putJson("/api/v1/admin/pages/{$page->id}", [
                'title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true,
                'sections' => [['key' => 'legal_hero', 'title' => 'Privacy Policy', 'data' => ['eyebrow' => 'INFORMAZIONI LEGALI', 'body' => 'Descrizione', 'last_updated_on' => '2026-01-01']]],
            ])->assertUnprocessable()->assertJsonValidationErrors('sections.0.data');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @param array<string,mixed> $attributes */
    private function makePage(array $attributes): Page
    {
        return Page::query()->updateOrCreate(
            ['internal_key' => (string) $attributes['internal_key']],
            ['template' => 'default', 'canonical_url' => null, ...$attributes],
        );
    }
}
