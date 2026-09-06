<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\ConventionPartner;
use App\Models\Page;
use App\Models\SiteIndexPage;
use App\Models\User;
use App\Services\SiteIndexPageInitializer;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConventionPartnerWebProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function convention_web_profiles_keep_master_data_read_only_and_never_publish_an_individual_route(): void
    {
        $partner = ConventionPartner::query()->create(['name' => 'Fondo Test', 'type' => 'fund', 'is_active' => true, 'sort_order' => 1]);

        $this->getJson('/api/v1/admin/convenzioni?per_page=15')->assertOk()
            ->assertJsonPath('data.0.master.name', 'Fondo Test')
            ->assertJsonPath('data.0.web_profile', null);

        $this->putJson("/api/v1/admin/convenzioni/{$partner->id}", $this->payload(['title' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->putJson("/api/v1/admin/convenzioni/{$partner->id}", $this->payload())->assertOk()
            ->assertJsonPath('web_profile.is_web_enabled', false)
            ->assertJsonPath('web_profile.title', 'Fondo Test')
            ->assertJsonPath('web_profile.public_slug', 'fondo-test')
            ->assertJsonPath('web_profile.canonical_url', null)
            ->assertJsonPath('web_profile.faqs.0.question', 'La FAQ resta privata?')
            ->assertJsonPath('effective_public_visibility', false);

        $this->putJson("/api/v1/admin/convenzioni/{$partner->id}", $this->payload(['name' => 'Non ammesso']))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    #[Test]
    public function convention_index_migrates_the_static_editorial_page_and_retires_it_from_static_listing(): void
    {
        $page = Page::query()->create(['internal_key' => Page::CONVENTIONS_NETWORK_INTERNAL_KEY, 'slug' => Page::CONVENTIONS_NETWORK_SLUG, 'title' => 'Convenzioni esistenti', 'seo_title' => 'SEO esistente', 'is_active' => true, 'published_at' => now()->subMinute()]);
        $page->sections()->create(['key' => 'hero', 'title' => 'Hero esistente', 'content' => 'Copy esistente', 'extra_json' => ['eyebrow' => 'EYEBROW'], 'sort_order' => 0, 'is_active' => true]);
        $page->sections()->create(['key' => 'access_process', 'title' => 'Accesso', 'content' => 'Passaggi', 'extra_json' => ['items' => []], 'sort_order' => 1, 'is_active' => true]);
        $page->sections()->create(['key' => 'conventions_catalog', 'title' => 'Catalogo', 'content' => 'Elenco', 'extra_json' => [], 'sort_order' => 2, 'is_active' => true]);
        $page->sections()->create(['key' => 'contact_cta', 'title' => 'Contatti', 'content' => 'Scrivici', 'extra_json' => [], 'sort_order' => 3, 'is_active' => true]);
        $page->faqs()->create(['question' => 'Domanda esistente', 'answer' => 'Risposta esistente', 'sort_order' => 0, 'is_active' => true, 'is_structured_data' => true]);

        app(SiteIndexPageInitializer::class)->initialize();
        $index = SiteIndexPage::query()->where('internal_key', 'conventions_network_index')->firstOrFail();
        $this->assertSame('Hero esistente', $index->content['hero_title']);
        $this->assertSame('Domanda esistente', $index->faqs()->firstOrFail()->question);

        $listed = $this->getJson('/api/v1/admin/pages?per_page=15')->assertOk()->json('data');
        $this->assertNotContains(Page::CONVENTIONS_NETWORK_INTERNAL_KEY, collect($listed)->pluck('internal_key')->all());

        $content = $index->content;
        $content['catalog_body'] = 'Catalogo aggiornato dalla pagina indice';
        $this->putJson("/api/v1/admin/index-pages/{$index->id}", ['title' => 'Convenzioni esistenti', 'content' => $content, 'configuration' => $index->configuration, 'faqs' => [['question' => 'Nuova FAQ', 'answer' => 'Nuova risposta', 'is_active' => true, 'is_structured_data' => true]], 'is_active' => true, 'published_at' => now()->subMinute()->toIso8601String()])->assertOk();

        $this->assertSame('Catalogo aggiornato dalla pagina indice', $page->fresh()->sections()->where('key', 'conventions_catalog')->value('content'));
        $this->assertSame('Nuova FAQ', $page->fresh()->faqs()->firstOrFail()->question);
        $this->getJson('/api/v1/public/pages/convenzioni-e-network')->assertOk();
    }

    #[Test]
    public function convention_web_profiles_store_open_graph_and_twitter_image_overrides(): void
    {
        Storage::fake('public');
        $partner = ConventionPartner::query()->create(['name' => 'Rete Test', 'type' => 'network', 'is_active' => true, 'sort_order' => 1]);
        $this->putJson("/api/v1/admin/convenzioni/{$partner->id}", $this->payload(['title' => 'Rete Test', 'public_slug' => 'rete-test']))->assertOk();

        $this->postJson("/api/v1/admin/convenzioni/{$partner->id}/og-image", ['image' => UploadedFile::fake()->image('og.png')])->assertOk();
        $this->postJson("/api/v1/admin/convenzioni/{$partner->id}/twitter-image", ['image' => UploadedFile::fake()->image('twitter.png')])->assertOk();

        $profile = $partner->fresh()->webProfile;
        $this->assertNotNull($profile?->og_image_path);
        $this->assertNotNull($profile?->twitter_image_path);
    }

    private function payload(array $overrides = []): array
    {
        return [...[
            'title' => 'Fondo Test', 'public_slug' => 'fondo-test', 'is_web_enabled' => false, 'seo_title' => 'SEO', 'seo_description' => 'Descrizione SEO', 'seo_h1' => 'H1',
            'local_seo_title' => 'SEO locale', 'local_seo_description' => 'Descrizione locale', 'local_seo_h1' => 'H1 locale',
            'is_local_seo_enabled' => true, 'canonical_url' => null, 'robots' => 'noindex,nofollow',
            'og_title' => 'OG', 'og_description' => 'Descrizione OG', 'twitter_title' => 'Twitter', 'twitter_description' => 'Descrizione Twitter',
            'faqs' => [['question' => 'La FAQ resta privata?', 'answer' => 'Sì, finché non esisterà il template.', 'is_active' => true, 'is_structured_data' => true]],
        ], ...$overrides];
    }
}
