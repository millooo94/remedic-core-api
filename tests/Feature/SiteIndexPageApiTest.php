<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\BlogPost;
use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Section;
use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Models\User;
use App\Services\HomePagePublicProjection;
use App\Services\SiteIndexPageInitializer;
use App\Support\SiteIndexes\SiteIndexPageRegistry;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteIndexPageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function registry_is_closed_and_initializer_is_idempotent_and_missing_only(): void
    {
        $legacyPageCount = Page::query()->whereIn('slug', ['aree-mediche', 'equipe', 'check-up'])->count();
        $initializer = app(SiteIndexPageInitializer::class);
        $initializer->initialize();
        $medical = SiteIndexPage::query()->where('internal_key', 'medical_areas_index')->firstOrFail();
        $medical->update(['title' => 'Copy conservata']);
        $initializer->initialize();

        $this->assertSame(7, SiteIndexPage::query()->count());
        $this->assertSame('Copy conservata', $medical->fresh()->title);
        $this->assertSame(SiteIndexPageRegistry::KEYS, SiteIndexPage::query()->orderBy('id')->pluck('internal_key')->all());
        $this->assertFalse(SiteIndexPageRegistry::contains('arbitrary_index'));
        $this->assertSame('/aree-mediche', SiteIndexPage::query()->where('internal_key', 'medical_areas_index')->value('canonical_url'));
        $this->assertSame('/equipe', SiteIndexPage::query()->where('internal_key', 'equipe_index')->value('canonical_url'));
        $this->assertSame('/check-up', SiteIndexPage::query()->where('internal_key', 'checkups_index')->value('canonical_url'));
        $this->assertSame('/diagnostica', SiteIndexPage::query()->where('internal_key', 'diagnostics_index')->value('canonical_url'));
        $this->assertSame('/medicina-estetica', SiteIndexPage::query()->where('internal_key', 'aesthetic_medicine_index')->value('canonical_url'));
        $this->assertSame('/news', SiteIndexPage::query()->where('internal_key', 'news_index')->value('canonical_url'));
        $this->assertSame('/pillole-di-salute', SiteIndexPage::query()->where('internal_key', 'health_pills_index')->value('canonical_url'));
        $this->assertSame($legacyPageCount, Page::query()->whereIn('slug', ['aree-mediche', 'equipe', 'check-up'])->count());
        $this->assertSame(0, Section::query()->where('sectionable_type', SiteIndexPage::class)->count());
        $this->assertSame(0, FaqItem::query()->where('faqable_type', SiteIndexPage::class)->count());
    }

    #[Test]
    public function admin_edits_only_registered_typed_index_pages_and_keeps_publication_semantics(): void
    {
        $this->actingAsWebAdmin();
        app(SiteIndexPageInitializer::class)->initialize();
        $page = SiteIndexPage::query()->where('internal_key', 'checkups_index')->firstOrFail();
        $payload = SiteIndexPageRegistry::defaults('checkups_index')['content'];
        $payload['final_cta_eyebrow'] = 'Hai bisogno di aiuto?';

        $this->putJson("/api/v1/admin/index-pages/{$page->id}", [
            'title' => 'Prevenzione', 'content' => $payload, 'seo_title' => 'SEO', 'seo_h1' => 'H1',
            'seo_description' => 'Descrizione', 'is_active' => true, 'published_at' => now()->subMinute()->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.publication_state', 'published')
            ->assertJsonPath('data.content.final_cta_eyebrow', 'Hai bisogno di aiuto?');

        $page->update(['published_at' => now()->addDay()]);
        $this->assertSame('scheduled', $page->fresh()->publicationState()->value);
        $page->update(['is_active' => false]);
        $this->assertSame('suspended', $page->fresh()->publicationState()->value);

        $arbitrary = SiteIndexPage::query()->create(['internal_key' => 'arbitrary_index', 'title' => 'No', 'slug' => 'no', 'content' => []]);
        $this->putJson("/api/v1/admin/index-pages/{$arbitrary->id}", ['title' => 'No', 'content' => []])->assertNotFound();
        $this->getJson('/api/v1/public/site-indexes/arbitrary_index')->assertNotFound();
    }

    #[Test]
    public function public_area_and_equipe_indexes_are_safe_runtime_projections_with_search_and_area_filter(): void
    {
        $this->publishIndexes('medical_areas_index', 'equipe_index');
        $area = Specialization::query()->create(['name' => 'Cardiologia', 'slug' => 'cardiologia-master', 'is_active' => true]);
        SpecializationWebProfile::query()->create(['specialization_id' => $area->id, 'slug' => 'cardiologia', 'short_description' => 'Cuore', 'is_web_enabled' => true, 'list_sort_order' => 2]);
        $hidden = Specialization::query()->create(['name' => 'Nascosta', 'slug' => 'nascosta-master', 'is_active' => false]);
        SpecializationWebProfile::query()->create(['specialization_id' => $hidden->id, 'slug' => 'nascosta', 'is_web_enabled' => true]);
        $professional = Professional::factory()->create(['full_name' => 'Ada Rossi', 'honorific_prefix' => 'Dott.ssa', 'avatar_path' => 'professionals/ada.jpg', 'is_active' => true]);
        $professional->specializations()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);
        ProfessionalPublicProfile::query()->create(['professional_id' => $professional->id, 'slug' => 'ada-rossi', 'title_prefix' => 'Cardiologa', 'is_web_enabled' => true, 'sort_order' => 1]);

        $this->getJson('/api/v1/public/site-indexes/medical_areas_index?q=cardio')->assertOk()
            ->assertJsonPath('data.result_count', 1)
            ->assertJsonPath('data.items.0.public_slug', 'cardiologia')
            ->assertJsonPath('data.items.0.href', '/aree-mediche/cardiologia')
            ->assertJsonPath('data.items.0.short_description', 'Cuore');
        $this->getJson('/api/v1/public/site-indexes/equipe_index?q=Ada&area=cardiologia')->assertOk()
            ->assertJsonPath('data.result_count', 1)
            ->assertJsonPath('data.items.0.href', '/equipe/ada-rossi')
            ->assertJsonPath('data.items.0.primary_area.public_slug', 'cardiologia')
            ->assertJsonPath('data.available_areas.0.public_slug', 'cardiologia');
        $this->getJson('/api/v1/public/site-indexes/equipe_index?area=nascosta')->assertOk()->assertJsonPath('data.result_count', 0);
    }

    #[Test]
    public function public_checkup_index_uses_real_ordered_data_and_never_exceeds_six(): void
    {
        $this->publishIndexes('checkups_index');
        $service = Service::factory()->create(['category_id' => null, 'display_name' => 'ECG', 'canonical_name' => 'ECG', 'is_active' => true, 'default_duration_minutes' => 20]);
        ServiceWebProfile::query()->create(['service_id' => $service->id, 'public_slug' => 'ecg', 'is_web_enabled' => true]);
        foreach (range(1, 7) as $number) {
            $checkup = Checkup::query()->create(['display_name' => "Check-up {$number}", 'price_amount' => 100, 'indicative_duration_minutes' => 30, 'is_active' => true]);
            $checkup->items()->create(['service_id' => $service->id, 'sort_order' => 0]);
            CheckupWebProfile::query()->create(['checkup_id' => $checkup->id, 'public_slug' => "checkup-{$number}", 'category_label' => 'Prevenzione', 'short_description' => 'Percorso', 'is_web_enabled' => true, 'list_sort_order' => $number]);
        }

        $this->getJson('/api/v1/public/site-indexes/checkups_index')->assertOk()
            ->assertJsonPath('data.result_count', 6)
            ->assertJsonCount(6, 'data.items')
            ->assertJsonPath('data.items.0.anchor', 'checkup-checkup-1')
            ->assertJsonPath('data.items.0.href', '/check-up/checkup-1')
            ->assertJsonPath('data.items.0.duration_label', '30 min')
            ->assertJsonPath('data.items.0.included_services.0.href', '/prestazioni/ecg')
            ->assertJsonPath('data.final_cta.action', 'contact');
    }

    #[Test]
    public function news_and_health_pill_indexes_project_published_blog_posts_with_canonical_hrefs(): void
    {
        $this->publishIndexes('news_index', 'health_pills_index');
        $news = BlogPost::query()->create(['title' => 'Nuova tecnologia', 'slug' => 'nuova-tecnologia', 'content_type' => 'news', 'editorial_category' => 'technology', 'excerpt' => 'Aggiornamento', 'is_active' => true, 'published_at' => now()->subMinute()]);
        BlogPost::query()->create(['title' => 'Pillola cuore', 'slug' => 'pillola-cuore', 'content_type' => 'health_pill', 'editorial_category' => 'cardiology', 'is_active' => true, 'published_at' => now()->subMinute()]);
        BlogPost::query()->create(['title' => 'Bozza', 'slug' => 'bozza-news', 'content_type' => 'news', 'editorial_category' => 'technology', 'is_active' => true, 'published_at' => null]);

        $this->getJson('/api/v1/public/site-indexes/news_index?q=tecnologia&category=technology')->assertOk()
            ->assertJsonPath('data.result_count', 1)->assertJsonPath('data.featured.href', '/news/nuova-tecnologia');
        $this->getJson('/api/v1/public/site-indexes/health_pills_index?category=cardiology')->assertOk()
            ->assertJsonPath('data.items.0.href', '/pillole-di-salute/pillola-cuore');
        $this->getJson('/api/v1/public/news/nuova-tecnologia')->assertOk()
            ->assertJsonPath('data.href', '/news/nuova-tecnologia')
            ->assertJsonPath('data.localized_routes', [['locale' => 'it', 'href' => '/news/nuova-tecnologia']]);
        $this->getJson('/api/v1/public/pillole-di-salute/pillola-cuore')->assertOk()
            ->assertJsonPath('data.href', '/pillole-di-salute/pillola-cuore')
            ->assertJsonPath('data.localized_routes', [['locale' => 'it', 'href' => '/pillole-di-salute/pillola-cuore']]);
        $this->assertSame('Nuova tecnologia', $news->title);
    }

    #[Test]
    public function diagnostic_and_aesthetic_indexes_are_effective_safe_projections(): void
    {
        $this->publishIndexes('diagnostics_index', 'aesthetic_medicine_index');
        $area = Specialization::query()->create(['name' => 'Radiologia', 'slug' => 'radiologia-master', 'is_active' => true]);
        SpecializationWebProfile::query()->create(['specialization_id' => $area->id, 'slug' => 'radiologia', 'is_web_enabled' => true]);
        $diagnostic = Service::query()->create(['category_id' => null, 'display_name' => 'Risonanza magnetica', 'canonical_name' => 'Risonanza magnetica', 'slug' => 'risonanza-master', 'default_duration_minutes' => 30, 'is_active' => true]);
        $diagnostic->specializations()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);
        ServiceWebProfile::query()->create(['service_id' => $diagnostic->id, 'public_slug' => 'risonanza', 'short_description' => 'Esame', 'is_web_enabled' => true, 'is_diagnostic' => true]);
        $aesthetic = Service::query()->create(['category_id' => null, 'display_name' => 'Biorivitalizzazione', 'canonical_name' => 'Biorivitalizzazione', 'slug' => 'biorivitalizzazione-master', 'default_duration_minutes' => 30, 'is_active' => true]);
        ServiceWebProfile::query()->create(['service_id' => $aesthetic->id, 'public_slug' => 'biorivitalizzazione', 'is_web_enabled' => true, 'is_aesthetic_medicine' => true, 'aesthetic_category' => 'skin_quality']);
        $hidden = Service::query()->create(['category_id' => null, 'display_name' => 'Nascosto', 'canonical_name' => 'Nascosto', 'slug' => 'nascosto-master', 'default_duration_minutes' => 30, 'is_active' => false]);
        ServiceWebProfile::query()->create(['service_id' => $hidden->id, 'public_slug' => 'nascosto', 'is_web_enabled' => true, 'is_diagnostic' => true]);

        $this->getJson('/api/v1/public/site-indexes/diagnostics_index?q=risonanza&filter=radiologia')->assertOk()
            ->assertJsonPath('data.result_count', 1)->assertJsonPath('data.items.0.href', '/prestazioni/risonanza')
            ->assertJsonPath('data.contact_cta.action', 'contact');
        $this->getJson('/api/v1/public/site-indexes/aesthetic_medicine_index?filter=skin_quality')->assertOk()
            ->assertJsonPath('data.result_count', 1)->assertJsonPath('data.items.0.aesthetic_category', 'skin_quality')
            ->assertJsonPath('data.final_cta.action', 'booking')->assertJsonCount(4, 'data.available_filters');
    }

    #[Test]
    public function homepage_index_actions_gain_canonical_hrefs_only_for_published_indexes(): void
    {
        $this->publishIndexes('medical_areas_index', 'equipe_index', 'checkups_index', 'diagnostics_index', 'aesthetic_medicine_index');
        $home = Page::query()->create(['internal_key' => 'home-index-actions', 'slug' => 'home-index-actions', 'title' => 'Home test']);
        foreach (['medical_areas', 'professionals', 'checkups', 'diagnostics', 'aesthetic_medicine'] as $order => $key) {
            $home->sections()->create(['key' => $key, 'title' => $key, 'sort_order' => $order, 'is_active' => true, 'extra_json' => []]);
        }

        $sections = collect(app(HomePagePublicProjection::class)->project($home, Request::create('/'))['sections'])->keyBy('key');
        $this->assertSame('/aree-mediche', $sections['medical_areas']['data']['index_action']['href']);
        $this->assertSame('/equipe', $sections['professionals']['data']['index_action']['href']);
        $this->assertSame('/check-up', $sections['checkups']['data']['index_action']['href']);
        $this->assertSame('/diagnostica', $sections['diagnostics']['data']['index_action']['href']);
        $this->assertSame('/medicina-estetica', $sections['aesthetic_medicine']['data']['index_action']['href']);

        SiteIndexPage::query()->where('internal_key', 'checkups_index')->update(['published_at' => null]);
        $draftSections = collect(app(HomePagePublicProjection::class)->project($home->fresh(), Request::create('/'))['sections'])->keyBy('key');
        $this->assertSame(['target' => 'checkups_index'], $draftSections['checkups']['data']['index_action']);
    }

    private function publishIndexes(string ...$keys): void
    {
        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->whereIn('internal_key', $keys)->update(['is_active' => true, 'published_at' => now()->subMinute()]);
    }

    private function actingAsWebAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
