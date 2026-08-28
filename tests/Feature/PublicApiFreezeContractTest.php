<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Page;
use App\Models\SiteIndexPage;
use App\Services\SiteIndexPageInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicApiFreezeContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('staticPageKeys')]
    public function every_static_page_has_the_frozen_public_envelope(string $internalKey, string $slug): void
    {
        $page = Page::query()->where('slug', $slug)->orWhere('internal_key', $internalKey)->firstOrNew();
        $page->fill([
            'internal_key' => $internalKey,
            'title' => "Pagina {$internalKey}",
            'slug' => $slug,
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ]);
        $page->save();

        $this->getJson("/api/v1/public/pages/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.internal_key', $internalKey)
            ->assertJsonPath('data.slug', $slug)
            ->assertJsonPath('data.href', '/'.$slug)
            ->assertJsonPath('data.locale', 'it')
            ->assertJsonPath('data.available_locales', ['it'])
            ->assertJsonPath('data.localized_routes', [['locale' => 'it', 'href' => '/'.$slug]])
            ->assertJsonStructure(['data' => ['sections', 'seo' => ['title', 'description', 'canonical_url', 'robots', 'open_graph']]]);
    }

    #[Test]
    #[DataProvider('siteIndexKeys')]
    public function every_site_index_has_the_frozen_public_envelope(string $key, string $path): void
    {
        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->where('internal_key', $key)->update(['is_active' => true, 'published_at' => now()->subMinute()]);

        $this->getJson("/api/v1/public/site-indexes/{$key}")
            ->assertOk()
            ->assertJsonPath('data.internal_key', $key)
            ->assertJsonPath('data.locale', 'it')
            ->assertJsonPath('data.canonical_url', $path)
            ->assertJsonPath('data.available_locales', ['it'])
            ->assertJsonPath('data.localized_routes', [['locale' => 'it', 'href' => $path]])
            ->assertJsonStructure(['data' => ['content', 'media', 'seo' => ['title', 'description', 'canonical_url', 'robots', 'open_graph']]]);
    }

    #[Test]
    public function compatibility_routes_are_not_allowed_to_define_editorial_canonicals(): void
    {
        $news = BlogPost::query()->create([
            'title' => 'Notizia', 'slug' => 'notizia', 'content_type' => 'news', 'is_active' => true, 'published_at' => now()->subMinute(),
        ]);
        $pill = BlogPost::query()->create([
            'title' => 'Pillola', 'slug' => 'pillola', 'content_type' => 'health_pill', 'is_active' => true, 'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/public/news/'.$news->slug)
            ->assertOk()
            ->assertJsonPath('data.href', '/news/notizia')
            ->assertJsonPath('data.seo.canonical_url', null);
        $this->getJson('/api/v1/public/pillole-di-salute/'.$pill->slug)
            ->assertOk()
            ->assertJsonPath('data.href', '/pillole-di-salute/pillola')
            ->assertJsonPath('data.seo.canonical_url', null);
    }

    #[Test]
    public function public_collections_never_need_database_primary_keys_or_sort_orders(): void
    {
        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->where('internal_key', 'checkups_index')->update(['is_active' => true, 'published_at' => now()->subMinute()]);

        $this->getJson('/api/v1/public/site-indexes/checkups_index')
            ->assertOk()
            ->assertJsonMissingPath('data.items.0.id')
            ->assertJsonMissingPath('data.items.0.list_sort_order');
    }

    #[Test]
    #[DataProvider('localizedPagePaths')]
    public function localized_public_paths_never_fall_back_to_italian(string $locale, string $slug, string $href): void
    {
        $page = Page::query()->create([
            'internal_key' => 'localized-freeze-'.$locale,
            'title' => 'Pagina italiana',
            'slug' => 'pagina-italiana-'.$locale,
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ]);
        $italian = $page->translations()->where('locale', 'it')->firstOrFail();
        if ($locale !== 'it') {
            $page->translations()->create([
                'locale' => $locale,
                'title' => "Page {$locale}",
                'slug' => $slug,
                'publication_state' => 'published',
                'source_revision' => $italian->source_revision,
                'reviewed_source_revision' => $italian->source_revision,
            ]);
        } else {
            $page->update(['slug' => $slug]);
        }

        $this->getJson("/api/v1/public/pages/{$slug}?locale={$locale}")
            ->assertOk()
            ->assertJsonPath('data.locale', $locale)
            ->assertJsonPath('data.slug', $slug)
            ->assertJsonPath('data.href', $href);
        if ($locale !== 'it') {
            $this->getJson("/api/v1/public/pages/pagina-italiana-{$locale}?locale={$locale}")->assertNotFound();
        }
    }

    /** @return array<string, array{string, string}> */
    public static function staticPageKeys(): array
    {
        return [
            'home' => ['home', 'home'],
            'center' => ['center', 'il-centro'],
            'why choose us' => ['why_choose_us', 'perche-sceglierci'],
            'plus health protocol' => ['plus_health_protocol', 'protocollo-piu-salute'],
            'contact' => ['contact', 'contatti'],
            'conventions network' => ['conventions_network', 'convenzioni-e-network'],
            'careers' => ['careers', 'lavora-con-noi'],
            'privacy' => ['privacy', 'privacy'],
            'cookie' => ['cookie-policy', 'cookie-policy'],
            'terms' => ['terms_of_service', 'termini-di-servizio'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function siteIndexKeys(): array
    {
        return [
            'medical areas' => ['medical_areas_index', '/aree-mediche'],
            'team' => ['equipe_index', '/equipe'],
            'checkups' => ['checkups_index', '/check-up'],
            'diagnostics' => ['diagnostics_index', '/diagnostica'],
            'aesthetic medicine' => ['aesthetic_medicine_index', '/medicina-estetica'],
            'news' => ['news_index', '/news'],
            'health pills' => ['health_pills_index', '/pillole-di-salute'],
        ];
    }

    /** @return array<string, array{string, string, string}> */
    public static function localizedPagePaths(): array
    {
        return [
            'italian' => ['it', 'pagina-it', '/pagina-it'],
            'english' => ['en', 'page-en', '/en/page-en'],
            'spanish' => ['es', 'pagina-es', '/es/pagina-es'],
            'french' => ['fr', 'page-fr', '/fr/page-fr'],
        ];
    }
}
