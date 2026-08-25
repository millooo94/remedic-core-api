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
        $this->assertSame('termini-di-servizio', $result['terms']->slug);
        $this->assertSame('/termini-di-servizio', $result['terms']->canonical_url);
        $this->assertNull($result['terms']->published_at);
        $this->assertSame(0, $result['terms']->faqs()->count());

        $scope = $result['terms']->sections()->where('key', 'scope')->firstOrFail();
        $scope->update(['extra_json' => ['blocks' => [['type' => 'paragraph', 'parts' => [['type' => 'text', 'text' => 'Copy editoriale futuro']]]]]]);
        app(LegalDocumentInitializer::class)->initialize();
        $this->assertSame('Copy editoriale futuro', $scope->fresh()->extra_json['blocks'][0]['parts'][0]['text']);
    }

    #[Test]
    public function public_legal_projection_exposes_only_active_safe_structured_content_and_runtime_center_values(): void
    {
        $privacy = $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        app(LegalDocumentInitializer::class)->initialize();
        SiteSetting::ensureSingleton()->update(['clinic_email' => 'privacy@remedic.test', 'clinic_phone' => '+39 095 111111', 'clinic_address' => 'Via Roma 1']);
        $privacy->sections()->where('key', 'minors')->update(['is_active' => false]);

        $this->getJson('/api/v1/public/pages/privacy')
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'legal_hero')
            ->assertJsonPath('data.sections.2.blocks.0.parts.1.value', 'privacy@remedic.test')
            ->assertJsonPath('data.sections.4.blocks.1.parts.1.href', '/cookie-policy')
            ->assertJsonPath('data.toc.0.href', '#scope')
            ->assertJsonMissing(['extra_json', 'minors']);
        $this->getJson('/api/v1/public/pages/termini-di-servizio')->assertNotFound();
    }

    #[Test]
    public function legal_editor_accepts_only_controlled_references(): void
    {
        $this->makePage(['internal_key' => 'privacy', 'slug' => 'privacy', 'title' => 'Privacy Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $this->makePage(['internal_key' => 'cookie-policy', 'slug' => 'cookie-policy', 'title' => 'Cookie Policy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $page = app(LegalDocumentInitializer::class)->initialize()['privacy'];

        $this->putJson("/api/v1/admin/pages/{$page->id}", [
            'title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true,
            'sections' => [['key' => 'scope', 'title' => 'Ambito', 'data' => ['blocks' => [['type' => 'paragraph', 'parts' => [['type' => 'internal_reference', 'target' => 'arbitrary']]]]]]],
        ])->assertUnprocessable();
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
