<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Page;
use App\Models\SiteIndexPage;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\SiteIndexPageInitializer;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeoAndRedirectContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function seo_configuration_is_singleton_and_has_an_independent_permission(): void
    {
        $seoManager = User::factory()->create(['role' => UserRole::Admin]);
        $seoManager->assignRole(Role::findByName(AdminRole::SEO_MANAGER->value, 'web'));
        Sanctum::actingAs($seoManager);

        $this->getJson('/api/v1/admin/seo')->assertOk();
        $this->putJson('/api/v1/admin/seo', [
            'site_url' => 'https://www.remedic.it/',
            'default_meta_title' => 'Remedic',
            'default_meta_description' => 'Centro <strong>medico</strong>',
            'seo_indexing_enabled' => true,
            'seo_sitemap_enabled' => true,
        ])->assertOk()->assertJsonPath('site_url', 'https://www.remedic.it/');
        $this->assertSame(1, SiteSetting::query()->count());

        $this->postJson('/api/v1/admin/redirects', ['from_path' => '/a', 'to_path' => '/b', 'http_code' => 301, 'is_active' => true])->assertForbidden();
    }

    #[Test]
    public function public_seo_contract_resolves_canonical_og_robots_and_a_deduplicated_sitemap(): void
    {
        SiteSetting::ensureSingleton()->update(['site_url' => 'https://www.remedic.it/api', 'site_name' => 'Remedic', 'default_meta_title' => 'Remedic', 'default_meta_description' => 'Default description', 'seo_indexing_enabled' => true, 'seo_sitemap_enabled' => true]);
        $page = Page::query()->create(['title' => 'SEO test', 'slug' => 'seo-test', 'canonical_url' => '/seo-test', 'excerpt' => '<p>Il nostro <strong>centro</strong></p>', 'is_active' => true, 'published_at' => now()->subMinute()]);
        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->where('internal_key', 'news_index')->update(['is_active' => true, 'published_at' => now()->subMinute()]);

        $this->getJson('/api/v1/public/pages/seo-test')->assertOk()
            ->assertJsonPath('data.seo.title', 'SEO test | Remedic')
            ->assertJsonPath('data.seo.description', 'Il nostro centro')
            ->assertJsonPath('data.seo.canonical_url', 'https://www.remedic.it/seo-test')
            ->assertJsonPath('data.seo.open_graph.type', 'website');
        $this->getJson('/api/v1/public/seo/robots')->assertOk()->assertJsonPath('data.sitemap_endpoint', '/api/v1/public/seo/sitemap');
        $this->getJson('/api/v1/public/seo/sitemap')->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonFragment(['path' => '/seo-test']);
    }

    #[Test]
    public function redirects_normalize_and_reject_self_loops_and_chains(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $admin->assignRole(Role::findByName(AdminRole::SEO_MANAGER->value, 'web'));
        $admin->givePermissionTo(AdminPermission::MANAGE_REDIRECTS->value);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/redirects', ['from_path' => '//vecchia//pagina/', 'to_path' => '/nuova-pagina/', 'http_code' => 301, 'is_active' => true])
            ->assertCreated()->assertJsonPath('from_path', '/vecchia/pagina')->assertJsonPath('to_path', '/nuova-pagina');
        $this->postJson('/api/v1/admin/redirects', ['from_path' => '/nuova-pagina', 'to_path' => '/finale', 'http_code' => 302, 'is_active' => true])->assertUnprocessable();
        $this->postJson('/api/v1/admin/redirects', ['from_path' => '/self', 'to_path' => '/self', 'http_code' => 301, 'is_active' => true])->assertUnprocessable();
        $this->postJson('/api/v1/admin/redirects', ['from_path' => '/danger', 'to_path' => 'javascript:alert(1)', 'http_code' => 301, 'is_active' => true])->assertUnprocessable();
        $this->getJson('/api/v1/public/redirects/resolve?path=%2Fvecchia%2F%2Fpagina')->assertOk()->assertJsonPath('data.matched', true)->assertJsonPath('data.destination', '/nuova-pagina');
        $this->getJson('/api/v1/public/redirects/resolve?path=%2Fmissing')->assertOk()->assertJsonPath('data.matched', false);
    }
}
