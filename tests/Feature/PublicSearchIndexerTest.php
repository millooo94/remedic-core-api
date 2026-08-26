<?php

namespace Tests\Feature;

use App\Enums\SupportedLocale;
use App\Models\BlogPost;
use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use App\Models\Page;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\SearchDocument;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Services\PublicSearchIndexer;
use App\Services\PublicSearchService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSearchIndexerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_is_idempotent_and_indexes_pages_indexes_news_and_health_pills_with_canonical_paths(): void
    {
        $page = $this->page('Più Salute', 'piu-salute');
        SiteIndexPage::query()->create(['internal_key' => 'medical_areas_index', 'title' => 'Aree mediche', 'slug' => 'aree-mediche', 'content' => ['body' => 'Specialità'], 'is_active' => true, 'published_at' => now()->subMinute()]);
        BlogPost::query()->create(['title' => 'News salute', 'slug' => 'news-salute', 'content_type' => 'news', 'editorial_category' => 'technology', 'excerpt' => 'Dettaglio', 'is_active' => true, 'published_at' => now()->subMinute()]);
        BlogPost::query()->create(['title' => 'Pillola salute', 'slug' => 'pillola-salute', 'content_type' => 'health_pill', 'editorial_category' => 'wellness', 'excerpt' => 'Dettaglio', 'is_active' => true, 'published_at' => now()->subMinute()]);
        $indexer = app(PublicSearchIndexer::class);

        $first = $indexer->rebuild();
        $snapshot = SearchDocument::query()->orderBy('source_type')->orderBy('source_id')->orderBy('locale')->get(['source_type', 'source_id', 'locale', 'result_type', 'href'])->toArray();
        $second = $indexer->rebuild();

        $this->assertSame($first, $second);
        $this->assertSame($snapshot, SearchDocument::query()->orderBy('source_type')->orderBy('source_id')->orderBy('locale')->get(['source_type', 'source_id', 'locale', 'result_type', 'href'])->toArray());
        $this->assertDatabaseHas('search_documents', ['source_type' => Page::class, 'source_id' => $page->id, 'result_type' => 'page', 'href' => '/piu-salute']);
        $this->assertDatabaseHas('search_documents', ['result_type' => 'index', 'href' => '/aree-mediche']);
        $this->assertDatabaseHas('search_documents', ['result_type' => 'news', 'href' => '/news/news-salute']);
        $this->assertDatabaseHas('search_documents', ['result_type' => 'health_pill', 'href' => '/pillole-di-salute/pillola-salute']);
    }

    public function test_reindex_tracks_localized_publication_structure_and_removal_without_fallback(): void
    {
        $page = $this->page('Pagina italiana', 'pagina-italiana');
        $italian = $page->translations()->where('locale', 'it')->firstOrFail();
        $english = $page->translations()->create(['locale' => 'en', 'title' => 'English page', 'slug' => 'english-page', 'excerpt' => 'English only', 'publication_state' => 'published', 'source_revision' => $italian->source_revision, 'reviewed_source_revision' => $italian->source_revision]);
        $section = $page->sections()->create(['key' => 'benefit', 'title' => 'Titolo sezione', 'content' => 'parola-sezione', 'is_active' => true]);
        $faq = $page->faqs()->create(['question' => 'Domanda FAQ', 'answer' => 'parola-faq', 'is_active' => true]);
        $section->translations()->create(['locale' => 'en', 'title' => 'Section heading', 'content' => 'section-keyword']);
        $faq->translations()->create(['locale' => 'en', 'question' => 'Question', 'answer' => 'faq-keyword']);
        $revision = $page->fresh()->translations()->where('locale', 'it')->value('source_revision');
        $english->update(['source_revision' => $revision, 'reviewed_source_revision' => $revision]);
        $indexer = app(PublicSearchIndexer::class);
        $indexer->reindex($page->fresh());

        $this->assertDatabaseHas('search_documents', ['source_type' => Page::class, 'source_id' => $page->id, 'locale' => 'en', 'href' => '/en/english-page', 'title' => 'English page']);
        $this->assertSame('Pagina italiana', app(PublicSearchService::class)->search('parola-faq', SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
        $page->update(['title' => 'Pagina italiana aggiornata']);
        $this->assertDatabaseMissing('search_documents', ['source_type' => Page::class, 'source_id' => $page->id, 'locale' => 'en']);
        $page->update(['is_active' => false]);
        $this->assertDatabaseMissing('search_documents', ['source_type' => Page::class, 'source_id' => $page->id, 'locale' => 'it']);
    }

    public function test_rebuild_removes_orphans_and_unique_source_locale_prevents_duplicates(): void
    {
        $page = $this->page('Pagina visibile', 'pagina-visibile');
        SearchDocument::query()->create(['source_type' => 'orphan', 'source_id' => 999, 'locale' => 'it', 'result_type' => 'page', 'href' => '/orphan', 'title' => 'Orphan', 'normalized_title' => 'orphan', 'normalized_text' => 'orphan', 'searchable_tokens' => 'orphan']);
        $indexer = app(PublicSearchIndexer::class);
        $indexer->rebuild();

        $this->assertDatabaseMissing('search_documents', ['source_type' => 'orphan']);
        $this->assertSame(1, SearchDocument::query()->where('source_type', Page::class)->where('source_id', $page->id)->where('locale', 'it')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        SearchDocument::query()->create(['source_type' => Page::class, 'source_id' => $page->id, 'locale' => 'it', 'result_type' => 'page', 'href' => '/duplicate', 'title' => 'Duplicato', 'normalized_title' => 'duplicato', 'normalized_text' => 'duplicato', 'searchable_tokens' => 'duplicato']);
    }

    public function test_rebuild_indexes_the_four_web_profile_result_types_with_their_canonical_paths(): void
    {
        $area = Specialization::query()->create(['name' => 'Dermatologia Search', 'slug' => 'dermatologia-search', 'is_active' => true]);
        $areaProfile = SpecializationWebProfile::query()->create(['specialization_id' => $area->id, 'slug' => 'dermatologia-search', 'short_description' => 'Cura della pelle', 'is_web_enabled' => true]);
        $category = ServiceCategory::query()->first() ?? ServiceCategory::factory()->create(['name' => 'Ricerca Search', 'slug' => 'ricerca-search']);
        $service = Service::factory()->create(['category_id' => $category->id, 'display_name' => 'Ecografia addominale', 'canonical_name' => 'Ecografia addominale', 'is_active' => true]);
        $serviceProfile = ServiceWebProfile::query()->create(['service_id' => $service->id, 'public_slug' => 'ecografia-addominale', 'short_description' => 'Diagnostica', 'is_web_enabled' => true]);
        $professional = Professional::factory()->create(['full_name' => 'Ada Liguori', 'is_active' => true]);
        $professionalProfile = ProfessionalPublicProfile::query()->create(['professional_id' => $professional->id, 'slug' => 'ada-liguori', 'short_bio' => 'Medico', 'is_web_enabled' => true]);
        $checkup = Checkup::factory()->create(['display_name' => 'Check-up prevenzione', 'is_active' => true]);
        $checkupProfile = CheckupWebProfile::query()->create(['checkup_id' => $checkup->id, 'public_slug' => 'check-up-prevenzione', 'short_description' => 'Controllo', 'category_label' => 'Prevenzione', 'is_web_enabled' => true]);

        app(PublicSearchIndexer::class)->rebuild();

        $this->assertDatabaseHas('search_documents', ['source_type' => SpecializationWebProfile::class, 'source_id' => $areaProfile->id, 'result_type' => 'medical_area', 'href' => '/aree-mediche/dermatologia-search']);
        $this->assertDatabaseHas('search_documents', ['source_type' => ServiceWebProfile::class, 'source_id' => $serviceProfile->id, 'result_type' => 'service', 'href' => '/prestazioni/ecografia-addominale']);
        $this->assertDatabaseHas('search_documents', ['source_type' => ProfessionalPublicProfile::class, 'source_id' => $professionalProfile->id, 'result_type' => 'professional', 'href' => '/equipe/ada-liguori']);
        $this->assertDatabaseHas('search_documents', ['source_type' => CheckupWebProfile::class, 'source_id' => $checkupProfile->id, 'result_type' => 'checkup', 'href' => '/check-up/check-up-prevenzione']);
    }

    public function test_localized_service_paths_are_indexed_only_when_the_translation_is_public_and_reviewed(): void
    {
        $category = ServiceCategory::query()->first() ?? ServiceCategory::factory()->create(['name' => 'Local Search', 'slug' => 'local-search']);
        $service = Service::factory()->create(['category_id' => $category->id, 'display_name' => 'Prestazione italiana', 'canonical_name' => 'Prestazione italiana', 'is_active' => true]);
        $profile = ServiceWebProfile::query()->create(['service_id' => $service->id, 'public_slug' => 'prestazione-italiana', 'short_description' => 'Italiano', 'is_web_enabled' => true]);
        $italian = $profile->translations()->where('locale', 'it')->firstOrFail();
        foreach (['en' => ['English service', 'english-service'], 'es' => ['Servicio español', 'servicio-es'], 'fr' => ['Service français', 'service-fr']] as $locale => [$title, $slug]) {
            $profile->translations()->create(['locale' => $locale, 'title' => $title, 'slug' => $slug, 'short_description' => $title, 'publication_state' => 'published', 'source_revision' => $italian->source_revision, 'reviewed_source_revision' => $italian->source_revision]);
        }
        app(PublicSearchIndexer::class)->reindex($profile->fresh());

        $this->assertDatabaseHas('search_documents', ['source_type' => ServiceWebProfile::class, 'source_id' => $profile->id, 'locale' => 'en', 'href' => '/en/services/english-service', 'title' => 'English service']);
        $this->assertDatabaseHas('search_documents', ['source_type' => ServiceWebProfile::class, 'source_id' => $profile->id, 'locale' => 'es', 'href' => '/es/servicios/servicio-es', 'title' => 'Servicio español']);
        $this->assertDatabaseHas('search_documents', ['source_type' => ServiceWebProfile::class, 'source_id' => $profile->id, 'locale' => 'fr', 'href' => '/fr/prestations/service-fr', 'title' => 'Service français']);

        $checkup = Checkup::factory()->create(['display_name' => 'Check-up italiano', 'is_active' => true]);
        $checkupProfile = CheckupWebProfile::query()->create(['checkup_id' => $checkup->id, 'public_slug' => 'check-up-italiano', 'short_description' => 'Italiano', 'category_label' => 'Prevenzione', 'is_web_enabled' => true]);
        $checkupItalian = $checkupProfile->translations()->where('locale', 'it')->firstOrFail();
        $checkupProfile->translations()->create(['locale' => 'en', 'title' => 'English check-up', 'slug' => 'english-check-up', 'short_description' => 'English', 'publication_state' => 'published', 'source_revision' => $checkupItalian->source_revision, 'reviewed_source_revision' => $checkupItalian->source_revision]);
        app(PublicSearchIndexer::class)->reindex($checkupProfile->fresh());

        $this->assertDatabaseHas('search_documents', ['source_type' => CheckupWebProfile::class, 'source_id' => $checkupProfile->id, 'locale' => 'en', 'href' => '/en/check-ups/english-check-up', 'title' => 'English check-up']);
    }

    private function page(string $title, string $slug): Page
    {
        return Page::query()->create(['internal_key' => $slug, 'title' => $title, 'slug' => $slug, 'template' => 'default', 'excerpt' => 'Copy pubblico', 'is_active' => true, 'published_at' => now()->subMinute()]);
    }
}
