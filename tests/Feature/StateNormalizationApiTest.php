<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\BlogPost;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StateNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function pages_use_visibility_as_the_only_public_gate(): void
    {
        $this->actingAsAdmin();

        $visible = $this->page('state-page-visible', true);
        $hidden = $this->page('state-page-hidden', false);

        $this->getJson('/api/v1/admin/pages?per_page=100')
            ->assertOk()
            ->assertJsonFragment(['id' => $visible->id, 'is_active' => true])
            ->assertJsonMissing(['publication_state']);

        $this->getJson('/api/v1/public/pages/state-page-visible')->assertOk();
        $this->getJson('/api/v1/public/pages/state-page-hidden')->assertNotFound();
    }

    #[Test]
    public function blog_posts_expose_and_filter_the_four_canonical_publication_states(): void
    {
        $this->actingAsAdmin();

        $draft = $this->blogPost('state-post-draft', true, null);
        $scheduled = $this->blogPost('state-post-scheduled', true, now()->addDay());
        $published = $this->blogPost('state-post-published', true, now()->subDay());
        $suspended = $this->blogPost('state-post-suspended', false, now()->subDay());

        foreach ([
            'draft' => $draft,
            'scheduled' => $scheduled,
            'published' => $published,
            'suspended' => $suspended,
        ] as $state => $post) {
            $this->getJson("/api/v1/admin/blog-posts?publication_state={$state}&per_page=100")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $post->id)
                ->assertJsonPath('data.0.publication_state', $state)
                ->assertJsonPath('data.0.effective_public_visibility', $state === 'published');
        }

        $this->getJson('/api/v1/public/blog-posts/state-post-published')->assertOk();
        $this->getJson('/api/v1/public/blog-posts/state-post-draft')->assertNotFound();
        $this->getJson('/api/v1/public/blog-posts/state-post-scheduled')->assertNotFound();
        $this->getJson('/api/v1/public/blog-posts/state-post-suspended')->assertNotFound();
    }

    #[Test]
    public function blog_posts_can_be_filtered_by_editorial_content_type(): void
    {
        $this->actingAsAdmin();

        $pill = $this->blogPost('health-pill', true, now()->subDay());
        $pill->update(['content_type' => 'health_pill']);
        $news = $this->blogPost('news-item', true, now()->subDay());
        $news->update(['content_type' => 'news']);

        $this->getJson('/api/v1/admin/blog-posts?content_type=health_pill&per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pill->id)
            ->assertJsonPath('data.0.content_type', 'health_pill');

        $this->getJson('/api/v1/admin/blog-posts?content_type=news&per_page=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $news->id)
            ->assertJsonPath('data.0.content_type', 'news');
    }

    #[Test]
    public function content_deletion_cleans_morph_children_and_protects_legacy_checkup_pages(): void
    {
        $this->actingAsAdmin();

        $page = $this->page('deletable-page', true, now()->subDay());
        $section = $page->sections()->create([
            'key' => 'hero',
            'title' => 'Hero',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $faq = $page->faqs()->create([
            'question' => 'Domanda?',
            'answer' => 'Risposta.',
            'sort_order' => 0,
            'is_active' => true,
            'is_structured_data' => true,
        ]);

        $this->deleteJson("/api/v1/admin/pages/{$page->id}")->assertNoContent();
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
        $this->assertDatabaseMissing('faq_items', ['id' => $faq->id]);

        $legacy = Page::query()->where('slug', 'check-up')->firstOrFail();
        $legacySection = $legacy->sections()->create([
            'key' => 'hero',
            'title' => 'Legacy',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/admin/pages/{$legacy->id}")
            ->assertConflict();
        $this->assertDatabaseHas('pages', ['id' => $legacy->id, 'slug' => 'check-up']);
        $this->assertDatabaseHas('sections', ['id' => $legacySection->id]);
    }

    #[Test]
    public function related_blog_articles_include_only_effectively_published_posts(): void
    {
        $published = $this->blogPost('related-published', true, now()->subDay());
        $this->blogPost('related-draft', true, null);
        $this->blogPost('related-scheduled', true, now()->addDay());
        $this->blogPost('related-suspended', false, now()->subDay());
        $source = $this->blogPost('source-post', true, now()->subDay());
        $source->relatedArticles()->attach([
            $published->id => ['sort_order' => 0],
            BlogPost::query()->where('slug', 'related-draft')->value('id') => ['sort_order' => 1],
            BlogPost::query()->where('slug', 'related-scheduled')->value('id') => ['sort_order' => 2],
            BlogPost::query()->where('slug', 'related-suspended')->value('id') => ['sort_order' => 3],
        ]);

        $this->getJson('/api/v1/public/blog-posts/source-post')
            ->assertOk()
            ->assertJsonPath('data.related_articles.0.title', 'Related Published')
            ->assertJsonPath('data.related_articles.0.href', '/news/related-published')
            ->assertJsonPath('data.related_articles.0.content_type', null);
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    private function page(string $slug, bool $isActive): Page
    {
        return Page::query()->create([
            'title' => str($slug)->headline(),
            'slug' => $slug,
            'template' => 'default',
            'is_active' => $isActive,
        ]);
    }

    private function blogPost(string $slug, bool $isActive, mixed $publishedAt): BlogPost
    {
        return BlogPost::query()->create([
            'title' => str($slug)->headline(),
            'slug' => $slug,
            'is_active' => $isActive,
            'published_at' => $publishedAt,
        ]);
    }
}
