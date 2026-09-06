<?php

namespace Tests\Feature;

use App\Contracts\TranslationProvider;
use App\Enums\AdminRole;
use App\Enums\SupportedLocale;
use App\Exceptions\TranslationProviderUnavailableException;
use App\Models\Page;
use App\Models\SitePopup;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TranslationGenerationApiTest extends TestCase
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
    public function an_allowed_editorial_page_is_translated_to_needs_review_without_publishing(): void
    {
        $page = $this->page();
        $this->fakeProvider();

        $this->postJson('/api/v1/admin/translation-generations', ['type' => 'pages', 'id' => $page->id, 'locales' => ['en']])
            ->assertOk()
            ->assertJsonPath('data.results.0.locale', 'en')
            ->assertJsonPath('data.results.0.status', 'needs_review');

        $this->assertDatabaseHas('content_translations', ['translatable_type' => Page::class, 'translatable_id' => $page->id, 'locale' => 'en', 'title' => 'EN Pagina italiana', 'publication_state' => 'draft', 'reviewed_source_revision' => null]);
    }

    #[Test]
    public function italian_and_private_or_unregistered_resources_are_rejected(): void
    {
        $page = $this->page();
        $this->fakeProvider();

        $this->postJson('/api/v1/admin/translation-generations', ['type' => 'pages', 'id' => $page->id, 'locales' => ['it']])->assertUnprocessable();
        $this->postJson('/api/v1/admin/translation-generations', ['type' => 'career-applications', 'id' => $page->id, 'locales' => ['en']])->assertUnprocessable();
    }

    #[Test]
    public function reviewed_or_current_translation_requires_explicit_regeneration(): void
    {
        $page = $this->page();
        $this->fakeProvider();
        $source = $page->translations()->where('locale', 'it')->firstOrFail();
        $page->translations()->create(['locale' => 'fr', 'title' => 'Traduction humaine', 'slug' => 'traduction-humaine', 'publication_state' => 'published', 'source_revision' => $source->source_revision, 'reviewed_source_revision' => $source->source_revision]);

        $this->postJson('/api/v1/admin/translation-generations', ['type' => 'pages', 'id' => $page->id, 'locales' => ['fr']])->assertConflict();
        $this->postJson('/api/v1/admin/translation-generations', ['type' => 'pages', 'id' => $page->id, 'locales' => ['fr'], 'regenerate' => true])
            ->assertOk()->assertJsonPath('data.results.0.mode', 'regenerated');

        $this->assertDatabaseHas('content_translations', ['translatable_type' => Page::class, 'translatable_id' => $page->id, 'locale' => 'fr', 'title' => 'FR Pagina italiana', 'publication_state' => 'draft', 'reviewed_source_revision' => null]);
    }

    #[Test]
    public function unavailable_provider_returns_a_safe_error_and_leaves_no_partial_translation(): void
    {
        $page = $this->page();
        $this->app->instance(TranslationProvider::class, new class implements TranslationProvider
        {
            public function translate(array $segments, SupportedLocale $target): array
            {
                throw new TranslationProviderUnavailableException('not configured');
            }
        });

        $this->postJson('/api/v1/admin/translation-generations', ['type' => 'pages', 'id' => $page->id, 'locales' => ['es']])
            ->assertStatus(503)->assertJsonPath('code', 'translation_provider_unavailable');
        $this->assertDatabaseMissing('content_translations', ['translatable_type' => Page::class, 'translatable_id' => $page->id, 'locale' => 'es']);
    }

    #[Test]
    public function popup_generation_translates_only_its_five_editorial_fields_and_keeps_empty_values_empty(): void
    {
        $popup = SitePopup::query()->create(['is_active' => true, 'source_type' => 'manual', 'title' => 'Titolo italiano', 'body' => 'Testo italiano', 'primary_cta_label' => 'Prenota']);
        $revision = 'popup-source-revision';
        $popup->translations()->create(['locale' => 'it', 'eyebrow' => null, 'title' => 'Titolo italiano', 'body' => 'Testo italiano', 'primary_cta_label' => 'Prenota', 'secondary_cta_label' => null, 'publication_state' => 'published', 'source_revision' => $revision, 'reviewed_source_revision' => $revision]);
        $this->fakeProvider();

        $this->postJson('/api/v1/admin/translation-generations', ['type' => 'popup', 'id' => $popup->id, 'locales' => ['en']])
            ->assertOk()->assertJsonPath('data.results.0.status', 'needs_review')->assertJsonPath('data.results.0.translated_fields', ['title', 'body', 'primary_cta_label']);
        $this->assertDatabaseHas('site_popup_translations', ['site_popup_id' => $popup->id, 'locale' => 'en', 'eyebrow' => null, 'title' => 'EN Titolo italiano', 'body' => 'EN Testo italiano', 'primary_cta_label' => 'EN Prenota', 'secondary_cta_label' => null, 'publication_state' => 'draft', 'reviewed_source_revision' => null]);
    }

    private function page(): Page
    {
        return Page::query()->create(['internal_key' => 'translation-page-'.str()->lower(str()->random(8)), 'title' => 'Pagina italiana', 'slug' => 'pagina-italiana-'.str()->lower(str()->random(8)), 'template' => 'default', 'excerpt' => 'Testo editoriale', 'is_active' => true, 'published_at' => now()]);
    }

    private function fakeProvider(): void
    {
        $this->app->instance(TranslationProvider::class, new class implements TranslationProvider
        {
            public function translate(array $segments, SupportedLocale $target): array
            {
                return collect($segments)->map(fn (string $text): string => strtoupper($target->value).' '.$text)->all();
            }
        });
    }
}
