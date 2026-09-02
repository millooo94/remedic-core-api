<?php

namespace Tests\Feature;

use App\Enums\SupportedLocale;
use App\Models\Page;
use App\Models\SiteIndexPage;
use App\Services\SiteIndexPageInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MultilingualPublicContentApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function supported_locales_are_strict_and_italian_has_no_prefix(): void
    {
        $this->assertSame(SupportedLocale::IT, SupportedLocale::default());
        $this->assertSame(SupportedLocale::EN, SupportedLocale::normalize('en-GB'));
        $this->assertNull(SupportedLocale::normalize('de'));
        $this->getJson('/api/v1/public/pages/anything?locale=de')->assertUnprocessable();
    }

    #[Test]
    public function published_translation_is_required_and_becomes_stale_when_the_italian_source_changes(): void
    {
        $page = Page::query()->create([
            'title' => 'Pagina italiana',
            'slug' => 'pagina-italiana',
            'template' => 'default',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $italian = $page->translations()->where('locale', 'it')->firstOrFail();
        $english = $page->translations()->create([
            'locale' => 'en',
            'title' => 'English page',
            'slug' => 'english-page',
            'publication_state' => 'published',
            'source_revision' => $italian->source_revision,
            'reviewed_source_revision' => $italian->source_revision,
        ]);

        $this->getJson('/api/v1/public/pages/english-page?locale=en')
            ->assertOk()
            ->assertJsonPath('data.title', 'English page')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.available_locales', ['it', 'en'])
            ->assertJsonPath('data.localized_routes', [
                ['locale' => 'it', 'href' => '/pagina-italiana'],
                ['locale' => 'en', 'href' => '/en/english-page'],
            ]);
        $this->getJson('/api/v1/public/pages/pagina-italiana?locale=en')->assertNotFound();
        $this->getJson('/api/v1/public/pages/english-page?locale=fr')->assertNotFound();

        $page->update(['title' => 'Pagina italiana aggiornata']);
        $english->refresh();
        $this->assertNotSame($english->source_revision, $english->reviewed_source_revision);
        $this->getJson('/api/v1/public/pages/english-page?locale=en')->assertNotFound();

        $english->update(['reviewed_source_revision' => $english->source_revision]);
        $this->getJson('/api/v1/public/pages/english-page?locale=en')->assertOk();
    }

    #[Test]
    public function localized_routes_exclude_draft_and_stale_translations_without_an_italian_fallback(): void
    {
        $page = Page::query()->create([
            'title' => 'Pagina italiana',
            'slug' => 'pagina-italiana',
            'template' => 'default',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $italian = $page->translations()->where('locale', 'it')->firstOrFail();
        $page->translations()->create([
            'locale' => 'en',
            'title' => 'English page',
            'slug' => 'english-page',
            'publication_state' => 'published',
            'source_revision' => $italian->source_revision,
            'reviewed_source_revision' => $italian->source_revision,
        ]);
        $page->translations()->create([
            'locale' => 'es',
            'title' => 'Borrador',
            'slug' => 'borrador',
            'publication_state' => 'draft',
            'source_revision' => $italian->source_revision,
        ]);
        $page->translations()->create([
            'locale' => 'fr',
            'title' => 'À revoir',
            'slug' => 'a-revoir',
            'publication_state' => 'published',
            'source_revision' => $italian->source_revision,
            'reviewed_source_revision' => 'obsolete',
        ]);

        $this->getJson('/api/v1/public/pages/pagina-italiana')
            ->assertOk()
            ->assertJsonPath('data.available_locales', ['it', 'en'])
            ->assertJsonPath('data.localized_routes', [
                ['locale' => 'it', 'href' => '/pagina-italiana'],
                ['locale' => 'en', 'href' => '/en/english-page'],
            ]);
    }

    #[Test]
    public function site_indexes_project_routes_for_their_published_locales_in_canonical_order(): void
    {
        app(SiteIndexPageInitializer::class)->initialize();
        $index = SiteIndexPage::query()->where('internal_key', 'news_index')->firstOrFail();
        $index->update(['is_active' => true, 'published_at' => now()->subMinute()]);
        $index->translations()->create([
            'locale' => 'en',
            'title' => 'News',
            'slug' => 'news-index',
            'content' => [],
            'publication_state' => 'published',
            'source_revision' => $index->source_revision,
            'reviewed_source_revision' => $index->source_revision,
        ]);

        $this->getJson('/api/v1/public/site-indexes/news_index')
            ->assertOk()
            ->assertJsonPath('data.available_locales', ['it', 'en'])
            ->assertJsonPath('data.localized_routes', [
                ['locale' => 'it', 'href' => '/news'],
                ['locale' => 'en', 'href' => '/en/news'],
            ]);
    }

    #[Test]
    public function canonical_home_projection_exposes_only_its_published_locales(): void
    {
        Page::query()->create([
            'internal_key' => Page::HOME_INTERNAL_KEY,
            'title' => 'Home',
            'slug' => 'home',
            'template' => 'default',
            'is_active' => true,
            'published_at' => null,
        ]);

        $this->getJson('/api/v1/public/site/home')
            ->assertOk()
            ->assertJsonPath('data.locale', 'it')
            ->assertJsonPath('data.available_locales', ['it'])
            ->assertJsonPath('data.localized_routes', [['locale' => 'it', 'href' => '/']]);
    }

    #[Test]
    public function canonical_home_projection_ignores_a_legacy_future_date_without_regressing_localized_routes(): void
    {
        $home = Page::query()->create([
            'internal_key' => Page::HOME_INTERNAL_KEY,
            'title' => 'Home',
            'slug' => 'home',
            'template' => 'default',
            'is_active' => true,
            'published_at' => now()->addDay(),
        ]);
        $italian = $home->translations()->where('locale', 'it')->firstOrFail();
        $home->translations()->create([
            'locale' => 'en',
            'title' => 'Home',
            'slug' => 'home',
            'publication_state' => 'published',
            'source_revision' => $italian->source_revision,
            'reviewed_source_revision' => $italian->source_revision,
        ]);

        $this->getJson('/api/v1/public/site/home?locale=en')
            ->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.available_locales', ['it', 'en'])
            ->assertJsonPath('data.localized_routes', [
                ['locale' => 'it', 'href' => '/'],
                ['locale' => 'en', 'href' => '/en'],
            ]);
    }
}
