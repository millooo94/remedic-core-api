<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\BlogPost;
use App\Models\EditorialCategory;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EditorialCategoriesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create(['email' => 'editorial-categories@example.com']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function categories_are_crudable_and_scoped_by_content_type(): void
    {
        $pill = $this->postJson('/api/v1/admin/editorial-categories', ['content_type' => 'health_pill', 'name' => 'Sonno'])
            ->assertCreated()->assertJsonPath('data.content_type', 'health_pill');
        $pillId = (int) $pill->json('data.id');
        $this->postJson('/api/v1/admin/editorial-categories', ['content_type' => 'news', 'name' => 'Rassegna'])->assertCreated();

        $this->getJson('/api/v1/admin/editorial-categories?content_type=health_pill')
            ->assertOk()->assertJsonFragment(['id' => $pillId, 'content_type' => 'health_pill']);
        $this->putJson("/api/v1/admin/editorial-categories/{$pillId}", ['content_type' => 'health_pill', 'name' => 'Riposo'])
            ->assertOk()->assertJsonPath('data.name', 'Riposo');
        $this->deleteJson("/api/v1/admin/editorial-categories/{$pillId}")->assertNoContent();
    }

    #[Test]
    public function article_rejects_cross_type_category_and_more_than_three_related_articles(): void
    {
        $newsCategory = EditorialCategory::query()->where('content_type', 'news')->firstOrFail();
        $pill = BlogPost::query()->create(['title' => 'Pillola', 'slug' => 'pillola', 'content_type' => 'health_pill', 'is_active' => true]);
        $related = collect(range(1, 4))->map(fn (int $index) => BlogPost::query()->create([
            'title' => "Correlata {$index}", 'slug' => "correlata-{$index}", 'content_type' => 'health_pill', 'is_active' => true,
        ]));

        $this->putJson("/api/v1/admin/blog-posts/{$pill->id}", [
            'title' => $pill->title, 'slug' => $pill->slug, 'content_type' => 'health_pill',
            'editorial_category_id' => $newsCategory->id,
            'related_article_ids' => $related->pluck('id')->all(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['editorial_category_id', 'related_article_ids']);

        $pillCategory = EditorialCategory::query()->where('content_type', 'health_pill')->firstOrFail();
        $this->putJson("/api/v1/admin/blog-posts/{$pill->id}", [
            'title' => $pill->title, 'slug' => $pill->slug, 'content_type' => 'health_pill',
            'editorial_category_id' => $pillCategory->id,
            'related_article_ids' => $related->take(3)->pluck('id')->all(),
        ])->assertOk()
            ->assertJsonPath('editorial_category_id', $pillCategory->id)
            ->assertJsonMissingPath('editorial_category');
    }

    #[Test]
    public function blog_listing_filters_by_search_visibility_and_category_within_its_domain(): void
    {
        $pillCategory = EditorialCategory::query()->where('content_type', 'health_pill')->firstOrFail();
        $newsCategory = EditorialCategory::query()->where('content_type', 'news')->firstOrFail();
        BlogPost::query()->create(['title' => 'Cuore visibile', 'slug' => 'cuore-visibile', 'content_type' => 'health_pill', 'editorial_category_id' => $pillCategory->id, 'is_active' => true]);
        BlogPost::query()->create(['title' => 'Cuore nascosto', 'slug' => 'cuore-nascosto', 'content_type' => 'health_pill', 'editorial_category_id' => $pillCategory->id, 'is_active' => false]);
        BlogPost::query()->create(['title' => 'News visibile', 'slug' => 'news-visibile', 'content_type' => 'news', 'editorial_category_id' => $newsCategory->id, 'is_active' => true]);

        $this->getJson('/api/v1/admin/blog-posts?content_type=health_pill&q=cuore&is_active=1&editorial_category_id='.$pillCategory->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'cuore-visibile');
        $this->getJson('/api/v1/admin/blog-posts?content_type=news&editorial_category_id='.$pillCategory->id)
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
