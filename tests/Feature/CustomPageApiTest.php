<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomPageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);

        $user = User::factory()->create(['email' => 'custom-pages-admin@example.com']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function existing_pages_remain_standard_and_do_not_require_custom_content(): void
    {
        $page = Page::query()->create([
            'title' => 'Pagina standard',
            'slug' => 'pagina-standard',
            'template' => 'default',
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->assertSame('standard', $page->fresh()->content_kind);
        $this->getJson("/api/v1/admin/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('content_kind', 'standard')
            ->assertJsonMissing(['custom_html', 'custom_css', 'custom_javascript']);
        $this->getJson('/api/v1/public/pages/pagina-standard')
            ->assertOk()
            ->assertJsonPath('data.content_kind', 'standard')
            ->assertJsonMissing(['custom_content']);
    }

    #[Test]
    public function admin_can_create_a_draft_custom_page_and_persist_each_dedicated_content_channel(): void
    {
        $slug = 'pagina-custom-'.str()->lower(str()->random(8));
        $created = $this->postJson('/api/v1/admin/pages', $this->customPayload($slug))->assertCreated()
            ->assertJsonPath('content_kind', 'custom')
            ->assertJsonPath('custom_html', '<section><h1>Contenuto custom</h1></section>')
            ->assertJsonPath('custom_css', '.hero { color: rebeccapurple; }')
            ->assertJsonPath('custom_javascript', 'window.customPageReady = true;');

        $pageId = (int) $created->json('id');
        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'content_kind' => 'custom',
            'custom_html' => '<section><h1>Contenuto custom</h1></section>',
            'custom_css' => '.hero { color: rebeccapurple; }',
            'custom_javascript' => 'window.customPageReady = true;',
        ]);
        $this->getJson("/api/v1/admin/pages/{$pageId}")
            ->assertOk()
            ->assertJsonPath('content_kind', 'custom')
            ->assertJsonPath('custom_html', '<section><h1>Contenuto custom</h1></section>');

        $this->putJson("/api/v1/admin/pages/{$pageId}", [
            ...$this->customPayload($slug),
            'seo_title' => 'SEO custom aggiornato',
            'custom_html' => '<section><h1>Contenuto aggiornato</h1></section>',
            'custom_css' => '.hero { color: teal; }',
            'custom_javascript' => 'window.customPageReady = false;',
        ])->assertOk()
            ->assertJsonPath('seo_title', 'SEO custom aggiornato')
            ->assertJsonPath('custom_html', '<section><h1>Contenuto aggiornato</h1></section>')
            ->assertJsonPath('custom_css', '.hero { color: teal; }')
            ->assertJsonPath('custom_javascript', 'window.customPageReady = false;');
        $this->getJson("/api/v1/public/pages/{$slug}")->assertNotFound();

        $this->postJson('/api/v1/admin/pages', $this->customPayload($slug))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/admin/pages', $this->customPayload('news'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    #[Test]
    public function published_custom_pages_use_the_existing_public_seo_and_sitemap_contracts(): void
    {
        $slug = 'pagina-custom-pubblicata-'.str()->lower(str()->random(8));
        $page = Page::query()->create([
            ...$this->customPayload($slug),
            'published_at' => now()->subMinute(),
            'seo_title' => 'SEO custom',
            'seo_description' => 'Descrizione custom',
        ]);

        $this->getJson("/api/v1/public/pages/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.content_kind', 'custom')
            ->assertJsonPath('data.custom_content.html', '<section><h1>Contenuto custom</h1></section>')
            ->assertJsonPath('data.custom_content.css', '.hero { color: rebeccapurple; }')
            ->assertJsonPath('data.custom_content.javascript', 'window.customPageReady = true;')
            ->assertJsonPath('data.seo.title', 'SEO custom | Remedic');
        $this->getJson('/api/v1/public/seo/sitemap')
            ->assertOk()
            ->assertJsonFragment(['path' => '/'.$slug]);

        $this->putJson("/api/v1/admin/pages/{$page->id}", [
            ...$this->customPayload($slug),
            'published_at' => null,
        ])->assertOk()->assertJsonPath('publication_state', 'draft');
        $this->getJson("/api/v1/public/pages/{$slug}")->assertNotFound();
        $this->getJson('/api/v1/public/seo/sitemap')->assertOk()->assertJsonMissing(['path' => '/'.$slug]);
    }

    #[Test]
    public function custom_html_is_localized_but_css_and_javascript_remain_global_to_the_page(): void
    {
        $slug = 'pagina-custom-tradotta-'.str()->lower(str()->random(8));
        $page = Page::query()->create([
            ...$this->customPayload($slug),
            'published_at' => now()->subMinute(),
        ]);

        $endpoint = "/api/v1/admin/localized-content/pages/{$page->id}/en";
        $this->postJson($endpoint)->assertCreated();
        $this->putJson($endpoint, [
            'title' => 'Custom page',
            'slug' => 'custom-page',
            'custom_html' => '<script>window.shouldNotRun = true;</script>',
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_html');
        $this->putJson($endpoint, [
            'title' => 'Custom page',
            'slug' => 'custom-page',
            'custom_html' => '<section><h1>English custom content</h1></section>',
            'seo_title' => 'English custom SEO',
            'publication_state' => 'published',
        ])->assertOk()->assertJsonPath('data.translation.custom_html', '<section><h1>English custom content</h1></section>');

        $this->getJson('/api/v1/public/pages/custom-page?locale=en')
            ->assertOk()
            ->assertJsonPath('data.title', 'Custom page')
            ->assertJsonPath('data.custom_content.html', '<section><h1>English custom content</h1></section>')
            ->assertJsonPath('data.custom_content.css', '.hero { color: rebeccapurple; }')
            ->assertJsonPath('data.custom_content.javascript', 'window.customPageReady = true;')
            ->assertJsonPath('data.seo.title', 'English custom SEO | Remedic');
    }

    #[Test]
    public function custom_pages_reject_inline_script_and_style_channels_and_cannot_convert_standard_pages(): void
    {
        $this->postJson('/api/v1/admin/pages', [
            ...$this->customPayload('script-html-'.str()->lower(str()->random(8))),
            'custom_html' => '<script>window.shouldNotRun = true;</script>',
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_html');
        $this->postJson('/api/v1/admin/pages', [
            ...$this->customPayload('style-html-'.str()->lower(str()->random(8))),
            'custom_html' => '<style>body { display:none; }</style>',
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_html');

        $standard = Page::query()->create(['title' => 'Standard', 'slug' => 'standard-'.str()->lower(str()->random(8)), 'template' => 'default']);
        $this->putJson("/api/v1/admin/pages/{$standard->id}", [
            'title' => 'Standard',
            'slug' => $standard->slug,
            'template' => 'default',
            'content_kind' => 'custom',
        ])->assertUnprocessable()->assertJsonValidationErrors('content_kind');
    }

    /** @return array<string, mixed> */
    private function customPayload(string $slug): array
    {
        return [
            'title' => 'Pagina custom',
            'slug' => $slug,
            'template' => 'landing',
            'content_kind' => 'custom',
            'custom_html' => '<section><h1>Contenuto custom</h1></section>',
            'custom_css' => '.hero { color: rebeccapurple; }',
            'custom_javascript' => 'window.customPageReady = true;',
            'is_active' => true,
        ];
    }
}
