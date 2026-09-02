<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PageDifferentialContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);

        $user = User::factory()->create(['email' => 'page-content-admin@example.com']);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function omitting_page_relations_preserves_existing_sections_and_faqs(): void
    {
        [$page, $hero, $body, $firstFaq, $secondFaq] = $this->pageWithContent();

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->pagePayload($page, [
            'title' => 'Pagina aggiornata senza relazioni',
        ]))->assertOk();

        $this->assertDatabaseHas('sections', ['id' => $hero->id, 'key' => 'hero']);
        $this->assertDatabaseHas('sections', ['id' => $body->id, 'key' => 'body']);
        $this->assertDatabaseHas('faq_items', ['id' => $firstFaq->id, 'question' => 'Prima domanda']);
        $this->assertDatabaseHas('faq_items', ['id' => $secondFaq->id, 'question' => 'Seconda domanda']);
    }

    #[Test]
    public function page_section_and_faq_sync_preserve_identity_and_only_delete_explicit_removals(): void
    {
        [$page, $hero, $body, $firstFaq, $secondFaq] = $this->pageWithContent();
        $heroCreatedAt = $hero->created_at->toISOString();
        $secondFaqCreatedAt = $secondFaq->created_at->toISOString();

        $response = $this->putJson("/api/v1/admin/pages/{$page->id}", $this->pagePayload($page, [
            'sections' => [
                [
                    'key' => 'hero',
                    'title' => 'Hero aggiornato',
                    'subtitle' => 'Sottotitolo aggiornato',
                    'content' => 'Contenuto aggiornato',
                    'sort_order' => 1,
                    'is_active' => false,
                ],
                [
                    'key' => 'nuova-sezione-compatibile',
                    'title' => 'Nuova sezione',
                    'subtitle' => null,
                    'content' => 'Nuovo contenuto',
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            ],
            'removed_section_keys' => ['body'],
            'faqs' => [
                [
                    'id' => $secondFaq->id,
                    'question' => 'Seconda domanda aggiornata',
                    'answer' => 'Seconda risposta aggiornata',
                    'sort_order' => 0,
                    'is_active' => false,
                    'is_structured_data' => false,
                ],
                [
                    'question' => 'Nuova domanda',
                    'answer' => 'Nuova risposta',
                    'sort_order' => 1,
                    'is_active' => true,
                    'is_structured_data' => true,
                ],
            ],
            'removed_faq_ids' => [$firstFaq->id],
        ]))->assertOk();

        $newSectionId = (int) collect($response->json('sections'))->firstWhere('key', 'nuova-sezione-compatibile')['id'];
        $newFaqId = (int) collect($response->json('faqs'))->firstWhere('question', 'Nuova domanda')['id'];

        $hero->refresh();
        $secondFaq->refresh();

        $this->assertSame($heroCreatedAt, $hero->created_at->toISOString());
        $this->assertSame('Hero aggiornato', $hero->title);
        $this->assertSame(0, $hero->sort_order);
        $this->assertFalse($hero->is_active);
        $this->assertDatabaseMissing('sections', ['id' => $body->id]);
        $this->assertDatabaseHas('sections', ['id' => $newSectionId, 'key' => 'nuova-sezione-compatibile']);

        $this->assertSame($secondFaqCreatedAt, $secondFaq->created_at->toISOString());
        $this->assertSame('Seconda domanda aggiornata', $secondFaq->question);
        $this->assertSame(0, $secondFaq->sort_order);
        $this->assertFalse($secondFaq->is_active);
        $this->assertDatabaseMissing('faq_items', ['id' => $firstFaq->id]);
        $this->assertDatabaseHas('faq_items', ['id' => $newFaqId, 'question' => 'Nuova domanda']);
    }

    #[Test]
    public function public_page_keeps_the_same_active_ordered_content_contract_after_differential_updates(): void
    {
        [$page, $hero, $body, $firstFaq, $secondFaq] = $this->pageWithContent();

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->pagePayload($page, [
            'sections' => [
                ['key' => 'body', 'title' => 'Body', 'subtitle' => null, 'content' => 'Body', 'sort_order' => 0, 'is_active' => true],
                ['key' => 'hero', 'title' => 'Hero', 'subtitle' => null, 'content' => 'Hero', 'sort_order' => 1, 'is_active' => true],
            ],
            'faqs' => [
                ['id' => $secondFaq->id, 'question' => 'Seconda domanda', 'answer' => 'Seconda risposta', 'sort_order' => 0, 'is_active' => true, 'is_structured_data' => false],
                ['id' => $firstFaq->id, 'question' => 'Prima domanda', 'answer' => 'Prima risposta', 'sort_order' => 1, 'is_active' => true, 'is_structured_data' => true],
            ],
        ]))->assertOk();

        $this->getJson("/api/v1/public/pages/{$page->slug}")
            ->assertOk()
            ->assertJsonPath('data.sections.0.key', 'hero')
            ->assertJsonPath('data.sections.1.key', 'body')
            ->assertJsonPath('data.faq.0.question', 'Seconda domanda')
            ->assertJsonPath('data.faq.1.question', 'Prima domanda');
    }

    #[Test]
    public function legacy_checkup_page_rejects_section_and_faq_mutations_before_persistence(): void
    {
        $legacy = Page::query()->where('slug', 'check-up')->firstOrFail();
        $section = $legacy->sections()->create(['key' => 'legacy-hero', 'sort_order' => 0, 'is_active' => true]);
        $faq = $legacy->faqs()->create(['question' => 'Domanda', 'answer' => 'Risposta', 'sort_order' => 0, 'is_active' => true, 'is_structured_data' => true]);

        $this->putJson("/api/v1/admin/pages/{$legacy->id}", $this->pagePayload($legacy, [
            'sections' => [['key' => 'legacy-hero', 'title' => 'Tentativo', 'sort_order' => 0, 'is_active' => false]],
            'faqs' => [['id' => $faq->id, 'question' => 'Tentativo', 'answer' => 'Tentativo', 'sort_order' => 0, 'is_active' => false, 'is_structured_data' => false]],
        ]))->assertConflict();

        $this->assertDatabaseHas('sections', ['id' => $section->id, 'key' => 'legacy-hero', 'is_active' => true]);
        $this->assertDatabaseHas('faq_items', ['id' => $faq->id, 'question' => 'Domanda', 'is_active' => true]);
        $this->deleteJson("/api/v1/admin/pages/{$legacy->id}")->assertConflict();
    }

    #[Test]
    public function homepage_cannot_be_disabled_and_ignores_published_at_without_affecting_other_pages(): void
    {
        $home = Page::query()->create([
            'internal_key' => Page::HOME_INTERNAL_KEY,
            'title' => 'Homepage',
            'slug' => Page::HOME_SLUG,
            'template' => 'default',
            'hero_image_path' => 'pages/home/legacy.jpg',
            'hero_image_alt' => 'Immagine legacy',
            'is_active' => true,
            'published_at' => now()->addDay(),
        ]);
        $draft = Page::query()->create([
            'title' => 'Pagina bozza',
            'slug' => 'pagina-bozza',
            'template' => 'default',
            'is_active' => true,
            'published_at' => null,
        ]);

        $this->putJson("/api/v1/admin/pages/{$home->id}", [
            'title' => 'Homepage aggiornata',
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('publication_state', 'published')
            ->assertJsonPath('effective_public_visibility', true);

        $this->assertDatabaseHas('pages', ['id' => $home->id, 'title' => 'Homepage aggiornata', 'slug' => Page::HOME_SLUG, 'hero_image_path' => 'pages/home/legacy.jpg', 'hero_image_alt' => 'Immagine legacy', 'is_active' => true]);
        $this->getJson('/api/v1/public/site/home')->assertOk();
        $this->getJson("/api/v1/public/pages/{$draft->slug}")->assertNotFound();
    }

    /** @return array{Page, Section, Section, FaqItem, FaqItem} */
    private function pageWithContent(): array
    {
        $slug = 'pagina-differenziale-'.str()->lower(str()->random(8));
        $page = Page::query()->create([
            'internal_key' => $slug,
            'title' => 'Pagina differenziale',
            'slug' => $slug,
            'template' => 'default',
            'faq_enabled' => true,
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ]);
        $hero = $page->sections()->create(['key' => 'hero', 'title' => 'Hero', 'sort_order' => 0, 'is_active' => true]);
        $body = $page->sections()->create(['key' => 'body', 'title' => 'Body', 'sort_order' => 1, 'is_active' => true]);
        $firstFaq = $page->faqs()->create(['question' => 'Prima domanda', 'answer' => 'Prima risposta', 'sort_order' => 0, 'is_active' => true, 'is_structured_data' => true]);
        $secondFaq = $page->faqs()->create(['question' => 'Seconda domanda', 'answer' => 'Seconda risposta', 'sort_order' => 1, 'is_active' => true, 'is_structured_data' => true]);

        return [$page, $hero, $body, $firstFaq, $secondFaq];
    }

    /** @param array<string, mixed> $overrides */
    private function pagePayload(Page $page, array $overrides = []): array
    {
        return [...[
            'title' => $page->title,
            'slug' => $page->slug,
            'template' => $page->template?->value ?? $page->template,
            'faq_enabled' => (bool) $page->faq_enabled,
            'is_active' => (bool) $page->is_active,
            'published_at' => optional($page->published_at)?->toIso8601String(),
        ], ...$overrides];
    }
}
