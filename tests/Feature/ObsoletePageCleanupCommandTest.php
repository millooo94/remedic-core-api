<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Redirect;
use App\Services\ObsoletePageCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ObsoletePageCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dry_run_lists_only_the_allowlist_and_does_not_modify_data(): void
    {
        $catalog = $this->seedClassifiedCatalog();
        $before = $this->fingerprint($catalog['preserved']);
        $pageCount = Page::count();
        $sectionCount = $catalog['obsolete']->sections()->count();

        $this->artisan('pages:cleanup-obsolete --dry-run')
            ->expectsOutputToContain('Totale Page allowlisted trovate: '.count(ObsoletePageCleanupService::DELETE_SLUGS))
            ->expectsOutputToContain('Dry-run completato: nessun dato è stato modificato.')
            ->assertExitCode(0);

        $this->assertSame($pageCount, Page::count());
        $this->assertSame($sectionCount, $catalog['obsolete']->sections()->count());
        $this->assertSame($before, $this->fingerprint($catalog['preserved']));
        Storage::disk('public')->assertExists($catalog['media_path']);
    }

    #[Test]
    public function execute_removes_only_allowlisted_pages_owned_children_and_automatic_redirects_idempotently(): void
    {
        $catalog = $this->seedClassifiedCatalog();
        $preservedBefore = $this->fingerprint($catalog['preserved']);

        $this->artisan('pages:cleanup-obsolete --force')->assertExitCode(0);

        $this->assertSame(5, Page::count());
        $this->assertSame($preservedBefore, $this->fingerprint($catalog['preserved']));
        $this->assertDatabaseMissing('pages', ['slug' => 'chi-siamo']);
        $this->assertDatabaseMissing('pages', ['slug' => 'contatti']);
        $this->assertDatabaseMissing('pages', ['slug' => 'check-up-endocrinologico']);
        $this->assertDatabaseMissing('sections', ['id' => $catalog['section_id']]);
        $this->assertDatabaseMissing('faq_items', ['id' => $catalog['faq_id']]);
        $this->assertDatabaseMissing('redirects', ['id' => $catalog['automatic_redirect_id']]);
        $this->assertDatabaseHas('redirects', ['id' => $catalog['manual_redirect_id'], 'is_automatic' => false]);
        Storage::disk('public')->assertMissing($catalog['media_path']);

        foreach (['chi-siamo', 'contatti', 'check-up', 'equipe', 'prestazioni', 'specializzazioni'] as $slug) {
            $this->getJson('/api/v1/public/pages/'.$slug)->assertNotFound();
        }

        $this->artisan('pages:cleanup-obsolete --force')->assertExitCode(0);
        $this->assertSame(5, Page::count());
    }

    #[Test]
    public function an_unexpected_page_blocks_execution_and_is_preserved(): void
    {
        $catalog = $this->seedClassifiedCatalog();
        $unexpected = $this->createPage('unclassified-page');

        $this->artisan('pages:cleanup-obsolete --dry-run')->assertExitCode(1);
        $this->artisan('pages:cleanup-obsolete --force')->assertExitCode(1);

        $this->assertDatabaseHas('pages', ['id' => $unexpected->id]);
        $this->assertDatabaseHas('pages', ['id' => $catalog['obsolete']->id]);
    }

    /** @return array{obsolete: Page, preserved: list<Page>, section_id: int, faq_id: int, automatic_redirect_id: int, manual_redirect_id: int, media_path: string} */
    private function seedClassifiedCatalog(): array
    {
        Storage::fake('public');
        $obsolete = collect(ObsoletePageCleanupService::DELETE_SLUGS)
            ->map(fn (string $slug): Page => $this->createPage($slug));
        $page = $obsolete->firstWhere('slug', 'chi-siamo');
        $mediaPath = "pages/{$page->id}/hero/image/legacy.jpg";
        Storage::disk('public')->put($mediaPath, 'legacy-image');
        $section = $page->sections()->create(['key' => 'legacy', 'title' => 'Legacy', 'extra_json' => ['image_path' => $mediaPath], 'sort_order' => 0, 'is_active' => true]);
        $faq = $page->faqs()->create(['question' => 'Domanda', 'answer' => 'Risposta', 'sort_order' => 0, 'is_active' => true, 'is_structured_data' => true]);
        $automatic = Redirect::query()->create(['from_path' => '/chi-siamo-legacy', 'to_path' => '/chi-siamo', 'http_code' => 301, 'is_active' => true, 'is_automatic' => true, 'source_type' => Redirect::SOURCE_TYPE_PAGE, 'source_id' => $page->id]);
        $manual = Redirect::query()->create(['from_path' => '/manual-chi-siamo', 'to_path' => '/altro', 'http_code' => 301, 'is_active' => true, 'is_automatic' => false, 'source_type' => Redirect::SOURCE_TYPE_PAGE, 'source_id' => $page->id]);

        $preserved = [
            $this->createPage('il-centro', Page::CENTER_INTERNAL_KEY),
            $this->createPage('perche-sceglierci', Page::WHY_CHOOSE_US_INTERNAL_KEY),
            $this->createPage('protocollo-piu-salute', Page::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY),
            $this->createPage('privacy'),
            $this->createPage('cookie-policy'),
        ];

        return ['obsolete' => $page, 'preserved' => $preserved, 'section_id' => $section->id, 'faq_id' => $faq->id, 'automatic_redirect_id' => $automatic->id, 'manual_redirect_id' => $manual->id, 'media_path' => $mediaPath];
    }

    private function createPage(string $slug, ?string $internalKey = null): Page
    {
        return Page::query()->updateOrCreate(['slug' => $slug], [
            'internal_key' => $internalKey ?? $slug,
            'title' => str($slug)->replace('-', ' ')->title()->toString(),
            'slug' => $slug,
            'template' => 'default',
            'is_active' => true,
            'published_at' => now()->subMinute(),
        ]);
    }

    /** @param list<Page> $pages @return array<int, array<string, mixed>> */
    private function fingerprint(array $pages): array
    {
        return collect($pages)->mapWithKeys(fn (Page $page): array => [$page->id => $page->fresh(['sections', 'faqs'])->toArray()])->all();
    }
}
