<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Page;
use App\Models\SiteIndexPage;
use App\Models\SiteNavigation;
use App\Models\SiteSetting;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Models\User;
use App\Services\SiteIndexPageInitializer;
use App\Services\SiteNavigationInitializer;
use App\Services\SiteNavigationProjectionService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteNavigationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function initializer_is_singleton_idempotent_and_missing_only(): void
    {
        $initializer = app(SiteNavigationInitializer::class);
        $navigation = $initializer->initialize();
        $navigation->update(['configuration' => ['header' => [['key' => 'contact', 'is_active' => false, 'label' => 'Scrivici']]]]);
        $initializer->initialize();

        $this->assertSame(1, SiteNavigation::query()->count());
        $this->assertSame('Scrivici', SiteNavigation::query()->firstOrFail()->configuration['header'][0]['label']);
    }

    #[Test]
    public function admin_header_is_permission_protected_and_has_a_closed_schema(): void
    {
        $this->getJson('/api/v1/admin/site-navigation')->assertUnauthorized();
        $this->actingAsWebAdmin();
        $response = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->assertJsonCount(6, 'data.configuration.header');
        $header = $response->json('data.configuration.header');

        $this->putJson('/api/v1/admin/site-navigation/header', ['header' => [...$header, ['key' => 'external', 'is_active' => true, 'label' => 'No']]])->assertUnprocessable();
        [$header[0], $header[1]] = [$header[1], $header[0]];
        $header[1]['label'] = 'Centro Remedic';
        $header[1]['is_active'] = false;
        $this->putJson('/api/v1/admin/site-navigation/header', ['header' => $header])->assertOk()
            ->assertJsonPath('data.configuration.header.0.key', 'medical_areas_menu')
            ->assertJsonPath('data.configuration.header.1.label', 'Centro Remedic')
            ->assertJsonPath('data.configuration.header.1.is_active', false);
    }

    #[Test]
    public function header_visibility_persists_and_updates_the_public_navigation_immediately(): void
    {
        $this->createPage('center', 'il-centro', true);
        $this->actingAsWebAdmin();
        $header = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->json('data.configuration.header');

        foreach ([false, true, false, true, false, true] as $isActive) {
            foreach ($header as &$item) {
                if ($item['key'] === 'center_menu') {
                    $item['is_active'] = $isActive;
                }
            }
            unset($item);

            $this->putJson('/api/v1/admin/site-navigation/header', ['header' => $header])
                ->assertOk()
                ->assertJsonPath('data.configuration.header.0.is_active', $isActive);

            $stored = SiteNavigation::query()->firstOrFail()->fresh()->configuration['header'];
            $storedCenter = collect($stored)->firstWhere('key', 'center_menu');
            $this->assertSame($isActive, $storedCenter['is_active']);

            $publicItems = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items');
            $this->assertSame($isActive, collect($publicItems)->contains('key', 'center_menu'));
        }
    }

    #[Test]
    public function public_projection_resolves_header_targets_and_keeps_fixed_actions(): void
    {
        $this->createPage('center', 'il-centro', true);
        $this->createPage('contact', 'contatti', false);
        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->where('internal_key', 'diagnostics_index')->update(['is_active' => true]);

        $items = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items');
        $this->assertSame('center_menu', $items[0]['key']);
        $this->assertSame('/diagnostica', collect($items)->firstWhere('key', 'diagnostics')['href'] ?? null);
        $this->assertSame('/contatti', collect($items)->firstWhere('key', 'contact')['href'] ?? null);
        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.header.reserved_area.action', 'reserved_area')
            ->assertJsonPath('data.header.booking.action', 'booking')
            ->assertJsonPath('data.header.items.0.menu.groups.0.items.0.href', '/il-centro');
    }

    #[Test]
    public function active_header_links_remain_projected_when_their_page_content_is_draft(): void
    {
        $contact = Page::query()->where('internal_key', 'contact')->orWhere('slug', 'contatti')->first();
        $attributes = ['internal_key' => 'contact', 'title' => 'Contatti', 'slug' => 'contatti', 'is_active' => true];
        $contact ? $contact->update($attributes) : Page::query()->create($attributes);
        $this->actingAsWebAdmin();
        $header = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->json('data.configuration.header');

        $items = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items');
        $contact = collect($items)->firstWhere('key', 'contact');
        $this->assertSame('/contatti', $contact['href'] ?? null);

        foreach ($header as &$item) {
            if ($item['key'] === 'contact') {
                $item['is_active'] = false;
            }
        }
        unset($item);
        $this->putJson('/api/v1/admin/site-navigation/header', ['header' => $header])->assertOk();
        $items = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items');
        $this->assertFalse(collect($items)->contains('key', 'contact'));

        foreach ($header as &$item) {
            if ($item['key'] === 'contact') {
                $item['is_active'] = true;
            }
        }
        unset($item);
        $this->putJson('/api/v1/admin/site-navigation/header', ['header' => $header])->assertOk();
        $items = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items');
        $contact = collect($items)->firstWhere('key', 'contact');
        $this->assertSame('/contatti', $contact['href'] ?? null);
    }

    #[Test]
    public function center_mega_menu_keeps_item_ownership_separate_from_internal_destinations(): void
    {
        $this->createPage('plus_health_protocol', 'protocollo-piu-salute', true);
        $this->createPage('contact', 'contatti', true);
        $this->actingAsWebAdmin();
        $configuration = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.center_mega_menu');
        $territory = array_search('territory', array_column($configuration['groups'], 'key'), true);
        $knowRemedic = array_search('know_remedic', array_column($configuration['groups'], 'key'), true);
        $configuration['groups'][$knowRemedic]['items'][0]['target'] = 'plus_health_protocol';
        $configuration['groups'][$territory]['items'][0]['target'] = 'contact';
        [$configuration['groups'][$knowRemedic]['items'][0], $configuration['groups'][$knowRemedic]['items'][1]] = [$configuration['groups'][$knowRemedic]['items'][1], $configuration['groups'][$knowRemedic]['items'][0]];
        $configuration['groups'][$knowRemedic]['items'][0]['description'] = 'Una descrizione editoriale.';
        $configuration['groups'][$knowRemedic]['label'] = 'Scopri Remedic';
        $configuration['promo']['title'] = 'Scopri il centro';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.target', 'why_choose_us')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.description', 'Una descrizione editoriale.')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.label', 'Scopri Remedic')
            ->assertJsonPath('data.configuration.center_mega_menu.promo.title', 'Scopri il centro');

        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.header.items.0.menu.groups.0.items.1.href', '/protocollo-piu-salute')
            ->assertJsonPath('data.header.items.0.menu.groups.1.items.0.href', '/contatti');
    }

    #[Test]
    public function center_mega_menu_rejects_a_child_key_owned_by_another_group(): void
    {
        $this->actingAsWebAdmin();
        $configuration = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.center_mega_menu');
        $territory = array_search('territory', array_column($configuration['groups'], 'key'), true);
        $configuration['groups'][$territory]['items'][0]['key'] = 'center';

        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertUnprocessable();
    }

    #[Test]
    public function center_mega_menu_rejects_unknown_internal_destinations_and_keeps_external_destinations(): void
    {
        $this->actingAsWebAdmin();
        $configuration = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.center_mega_menu');
        $territory = array_search('territory', array_column($configuration['groups'], 'key'), true);
        $configuration['groups'][$territory]['items'][0]['target'] = 'not_a_navigation_target';

        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertUnprocessable();

        $configuration['groups'][$territory]['items'][0]['target'] = 'center';
        $configuration['groups'][$territory]['items'][0]['link_type'] = 'external';
        $configuration['groups'][$territory]['items'][0]['external_url'] = 'https://example.test/remedic';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.1.items.0.target', 'center')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.1.items.0.external_url', 'https://example.test/remedic');
    }

    #[Test]
    public function navigation_destinations_support_an_explicit_inert_none_state_without_retaining_previous_links(): void
    {
        $this->createPage('contact', 'contatti', true);
        $this->actingAsWebAdmin();
        $configuration = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.center_mega_menu');
        $territory = array_search('territory', array_column($configuration['groups'], 'key'), true);
        $item = &$configuration['groups'][$territory]['items'][0];
        $item['label'] = 'Scopri senza navigare';
        $item['link_type'] = 'internal';
        $item['target'] = null;

        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertUnprocessable();

        $item['link_type'] = 'external';
        $item['external_url'] = 'not-a-url';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertUnprocessable();

        $item['external_url'] = 'https://example.test/old-destination';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertOk();

        $item['link_type'] = 'none';
        $item['target'] = 'contact';
        $item['external_url'] = 'https://example.test/old-destination';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertOk()
            ->assertJsonPath("data.configuration.center_mega_menu.groups.$territory.items.0.link_type", 'none')
            ->assertJsonPath("data.configuration.center_mega_menu.groups.$territory.items.0.target", null)
            ->assertJsonPath("data.configuration.center_mega_menu.groups.$territory.items.0.external_url", null);

        $publicMenu = collect($this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items'))
            ->firstWhere('key', 'center_menu')['menu'];
        $publicItem = collect($publicMenu['groups'])->firstWhere('key', 'territory')['items'][0];
        $this->assertSame('Scopri senza navigare', $publicItem['label']);
        $this->assertSame('none', $publicItem['link_type']);
        $this->assertNull($publicItem['href']);

        $item['link_type'] = 'internal';
        $item['target'] = 'contact';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertOk()
            ->assertJsonPath("data.configuration.center_mega_menu.groups.$territory.items.0.target", 'contact');

        $item['link_type'] = 'external';
        $item['external_url'] = 'https://example.test/new-destination';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertOk()
            ->assertJsonPath("data.configuration.center_mega_menu.groups.$territory.items.0.external_url", 'https://example.test/new-destination');
    }

    #[Test]
    public function center_mega_menu_item_visibility_is_persisted_and_publicly_projected_per_child(): void
    {
        $this->createPage('center', 'il-centro', true);
        $this->createPage('why_choose_us', 'perche-remedic', true);
        $this->actingAsWebAdmin();
        $configuration = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.center_mega_menu');
        $knowRemedic = array_search('know_remedic', array_column($configuration['groups'], 'key'), true);
        $configuration['groups'][$knowRemedic]['label'] = 'Conosci Remedic';
        $configuration['groups'][$knowRemedic]['items'][0]['description'] = 'Il centro coordinato';
        $configuration['groups'][$knowRemedic]['items'][0]['is_active'] = false;

        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])
            ->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.label', 'Conosci Remedic')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.is_active', false)
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.description', 'Il centro coordinato');
        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonCount(1, 'data.header.items.0.menu.groups.0.items')
            ->assertJsonPath('data.header.items.0.menu.groups.0.items.0.target', 'why_choose_us');

        foreach ($configuration['groups'][$knowRemedic]['items'] as &$item) {
            $item['is_active'] = false;
        }
        unset($item);
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])->assertOk();
        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonCount(0, 'data.header.items.0.menu.groups');

        $configuration['groups'][$knowRemedic]['items'][0]['is_active'] = true;
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])->assertOk();
        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.header.items.0.menu.groups.0.items.0.target', 'center');
    }

    #[Test]
    public function promo_media_uses_its_managed_slot(): void
    {
        Storage::fake('public');
        $this->actingAsWebAdmin();

        $this->post('/api/v1/admin/site-navigation/center-mega-menu/media', ['file' => UploadedFile::fake()->image('promo.jpg')])
            ->assertOk()
            ->assertJsonPath('data.center_mega_menu_promo_image_url', fn (string $url): bool => str_contains($url, 'site-navigation/center-mega-menu-promo-image'));
        $this->deleteJson('/api/v1/admin/site-navigation/center-mega-menu/media')->assertOk()
            ->assertJsonPath('data.center_mega_menu_promo_image_url', null);
    }

    #[Test]
    public function center_group_item_icon_uses_a_managed_navigation_slot(): void
    {
        Storage::fake('public');
        $this->actingAsWebAdmin();

        $this->post('/api/v1/admin/site-navigation/center-mega-menu/groups/know_remedic/items/center/media', ['file' => UploadedFile::fake()->image('icon.png', 64, 64)])
            ->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.icon_url', fn (string $url): bool => str_contains($url, 'site-navigation/center-mega-menu-icons/know_remedic/center'));
        $this->deleteJson('/api/v1/admin/site-navigation/center-mega-menu/groups/know_remedic/items/center/media')->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.icon_url', null);
    }

    #[Test]
    public function legacy_flat_sections_are_normalized_into_editable_groups_without_losing_copy(): void
    {
        $navigation = app(SiteNavigationInitializer::class)->initialize();
        $configuration = $navigation->configuration;
        $configuration['center_mega_menu'] = [
            'sections' => [
                ['key' => 'know_remedic', 'label' => 'Il nostro centro', 'subtitle' => 'Cura coordinata', 'icon_path' => 'site-navigation/legacy-icon.png', 'link_type' => 'internal', 'target' => 'center', 'external_url' => null],
                ['key' => 'territory', 'label' => 'Sul territorio', 'subtitle' => null, 'icon_path' => null, 'link_type' => 'internal', 'target' => 'conventions_network', 'external_url' => null],
                ['key' => 'prevention', 'label' => 'Prevenzione', 'subtitle' => null, 'icon_path' => null, 'link_type' => 'internal', 'target' => 'checkups_index', 'external_url' => null],
                ['key' => 'information_health', 'label' => 'Informazione e salute', 'subtitle' => null, 'icon_path' => null, 'link_type' => 'internal', 'target' => 'news_index', 'external_url' => null],
            ],
            'promo' => ['eyebrow' => 'ESPLORA', 'title' => 'Conosci Remedic', 'body' => null, 'cta_label' => 'Scopri il centro', 'cta_target' => 'center'],
        ];
        $navigation->update(['configuration' => $configuration]);
        $this->actingAsWebAdmin();

        $this->getJson('/api/v1/admin/site-navigation')->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.label', 'Il nostro centro')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.target', 'center')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.description', 'Cura coordinata')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.items.0.icon_url', fn (string $url): bool => str_contains($url, 'site-navigation/legacy-icon.png'));
    }

    #[Test]
    public function medical_areas_menu_is_manual_ordered_and_publicly_effective(): void
    {
        $this->actingAsWebAdmin();
        $first = $this->area('Cardiologia', true);
        $hidden = $this->area('Dermatologia', false);
        $config = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.medical_areas_mega_menu');
        $config['specialization_ids'] = [$hidden->specialization_id, $first->specialization_id];
        $this->putJson('/api/v1/admin/site-navigation/medical-areas-mega-menu', ['medical_areas_mega_menu' => $config])->assertOk()
            ->assertJsonPath('data.configuration.medical_areas_mega_menu.specialization_ids.0', $hidden->specialization_id);

        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.header.items.1.key', 'medical_areas_menu')
            ->assertJsonCount(1, 'data.header.items.1.menu.items')
            ->assertJsonPath('data.header.items.1.menu.items.0.name', 'Cardiologia');
        $hidden->update(['is_web_enabled' => true]);
        $this->getJson('/api/v1/public/navigation')->assertJsonPath('data.header.items.1.menu.items.0.name', 'Dermatologia');

        $config['specialization_ids'] = array_fill(0, 13, $first->specialization_id);
        $this->putJson('/api/v1/admin/site-navigation/medical-areas-mega-menu', ['medical_areas_mega_menu' => $config])->assertUnprocessable();
    }

    #[Test]
    public function footer_is_closed_and_uses_runtime_center_data(): void
    {
        $this->actingAsWebAdmin();
        SiteSetting::ensureSingleton()->update(['clinic_phone' => '+39 0123', 'clinic_email' => 'info@example.test', 'legal_company_name' => 'Remedic Srl', 'vat_number' => 'IT123', 'instagram_url' => 'https://instagram.com/remedic', 'tiktok_url' => 'https://tiktok.com/@remedic']);
        $footer = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.footer');
        $footer['columns']['center']['items'][0] = ['label' => 'News', 'link_type' => 'internal', 'target' => 'news_index', 'external_url' => null];
        $footer['brand_description'] = 'Copy Footer';
        $this->putJson('/api/v1/admin/site-navigation/footer', ['footer' => $footer])->assertOk()
            ->assertJsonPath('data.configuration.footer.brand_description', 'Copy Footer')
            ->assertJsonPath('data.configuration.footer.columns.center.items.0.target', 'news_index');

        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.footer.brand.description', 'Copy Footer')
            ->assertJsonPath('data.footer.contact_visibility.address', true)
            ->assertJsonPath('data.footer.legal_visibility.privacy', true)
            ->assertJsonPath('data.footer.center.phone', '+39 0123')
            ->assertJsonPath('data.footer.legal.legal_company_name', 'Remedic Srl')
            ->assertJsonPath('data.footer.legal.links.1.target', 'cookie_policy')
            ->assertJsonPath('data.footer.legal.links.1.href', '/cookie-policy')
            ->assertJsonPath('data.footer.social.0.platform', 'instagram')
            ->assertJsonPath('data.footer.social.1.platform', 'tiktok');
    }

    #[Test]
    public function footer_columns_allow_ordered_editorial_links_and_independent_visibility(): void
    {
        $this->actingAsWebAdmin();
        $publish = static function (string $key, string $title, string $slug): void {
            $page = Page::query()->where('internal_key', $key)->orWhere('slug', $slug)->first();
            $attributes = ['internal_key' => $key, 'title' => $title, 'slug' => $slug, 'is_active' => true];
            $page ? $page->update($attributes) : Page::query()->create($attributes);
        };
        $publish('privacy', 'Privacy', 'privacy-policy');
        $publish('cookie_policy', 'Cookie', 'cookie-policy');
        $publish('terms_of_service', 'Termini', 'termini');
        $footer = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.footer');
        $footer['contact_visibility'] = ['address' => false, 'phone' => true, 'email' => true, 'hours' => false];
        $footer['legal_visibility'] = ['privacy' => true, 'cookie_policy' => false, 'terms_of_service' => true, 'cookie_preferences' => false];
        $footer['social_visibility'] = ['instagram' => true, 'facebook' => false, 'tiktok' => true, 'youtube' => false];
        $footer['columns']['information']['title'] = 'Assistenza';
        $footer['columns']['information']['items'] = [
            ['label' => 'Portale esterno', 'link_type' => 'external', 'target' => null, 'external_url' => 'https://example.test/help'],
            ['label' => 'Contatti', 'link_type' => 'internal', 'target' => 'contact', 'external_url' => null],
        ];

        $this->putJson('/api/v1/admin/site-navigation/footer', ['footer' => $footer])->assertOk()
            ->assertJsonPath('data.configuration.footer.contact_visibility.address', false)
            ->assertJsonPath('data.configuration.footer.contact_visibility.phone', true)
            ->assertJsonPath('data.configuration.footer.legal_visibility.cookie_policy', false)
            ->assertJsonPath('data.configuration.footer.social_visibility.facebook', false)
            ->assertJsonPath('data.configuration.footer.columns.information.items.0.external_url', 'https://example.test/help');

        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.footer.contact_visibility.address', false)
            ->assertJsonPath('data.footer.contact_visibility.phone', true)
            ->assertJsonPath('data.footer.legal_visibility.cookie_policy', false)
            ->assertJsonPath('data.footer.legal.links.0.target', 'privacy')
            ->assertJsonPath('data.footer.legal.links.1.target', 'terms_of_service')
            ->assertJsonMissingPath('data.footer.legal.links.2')
            ->assertJsonPath('data.footer.social_visibility.facebook', false);

        $footer['columns']['information']['items'] = array_fill(0, 6, ['label' => 'Link', 'link_type' => 'internal', 'target' => 'contact', 'external_url' => null]);
        $this->putJson('/api/v1/admin/site-navigation/footer', ['footer' => $footer])->assertUnprocessable();
    }

    #[Test]
    public function legacy_footer_configuration_defaults_each_visibility_flag_to_true(): void
    {
        $navigation = app(SiteNavigationInitializer::class)->initialize();
        $configuration = $navigation->configuration;
        unset($configuration['footer']['contact_visibility'], $configuration['footer']['legal_visibility'], $configuration['footer']['social_visibility']);
        $navigation->update(['configuration' => $configuration]);
        $this->actingAsWebAdmin();

        $this->getJson('/api/v1/admin/site-navigation')->assertOk()
            ->assertJsonPath('data.configuration.footer.contact_visibility.address', true)
            ->assertJsonPath('data.configuration.footer.contact_visibility.hours', true)
            ->assertJsonPath('data.configuration.footer.legal_visibility.cookie_preferences', true);
        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.footer.contact_visibility.email', true)
            ->assertJsonPath('data.footer.legal_visibility.terms_of_service', true);
    }

    #[Test]
    public function legacy_center_groups_are_normalized_to_three_persistible_slots_without_losing_existing_data(): void
    {
        $navigation = app(SiteNavigationInitializer::class)->initialize();
        $configuration = $navigation->configuration;
        $groups = &$configuration['center_mega_menu']['groups'];
        $territory = array_search('territory', array_column($groups, 'key'), true);
        $prevention = array_search('prevention', array_column($groups, 'key'), true);
        $information = array_search('information_health', array_column($groups, 'key'), true);
        array_pop($groups[$territory]['items']);
        array_pop($groups[$prevention]['items']);
        array_pop($groups[$prevention]['items']);
        array_pop($groups[$information]['items']);
        $groups[$territory]['items'][0]['label'] = 'Convenzioni locali';
        $groups[$territory]['items'][0]['description'] = 'Una descrizione preservata';
        $groups[$territory]['items'][0]['icon_path'] = 'site-navigation/legacy-territory.png';
        $groups[$territory]['items'][0]['target'] = 'contact';
        foreach ($groups as &$group) {
            unset($group['is_active']);
        }
        unset($group);
        $navigation->update(['configuration' => $configuration]);
        $this->actingAsWebAdmin();

        $normalized = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->json('data.configuration.center_mega_menu.groups');
        $this->assertSame([3, 3, 3, 3], array_map(static fn (array $group): int => count($group['items']), $normalized));
        $this->assertTrue(collect($normalized)->every('is_active', true));
        $territory = collect($normalized)->firstWhere('key', 'territory');
        $this->assertSame(['conventions_network', 'careers', 'territory_slot_3'], array_column($territory['items'], 'key'));
        $this->assertSame('Convenzioni locali', $territory['items'][0]['label']);
        $this->assertSame('Una descrizione preservata', $territory['items'][0]['description']);
        $this->assertSame('contact', $territory['items'][0]['target']);
        $this->assertFalse($territory['items'][2]['is_active']);
        $this->assertSame('none', $territory['items'][2]['link_type']);
        $this->assertNull($territory['items'][2]['target']);
        $this->assertNull($territory['items'][2]['label']);
        $this->assertNull($territory['items'][2]['description']);
        $this->assertNull($territory['items'][2]['icon_url']);
    }

    #[Test]
    public function center_group_visibility_and_group_and_item_order_are_persisted_and_publicly_projected(): void
    {
        $this->createPage('center', 'il-centro', true);
        $this->actingAsWebAdmin();
        $menu = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->json('data.configuration.center_mega_menu');
        foreach ($menu['groups'] as &$group) {
            $group['is_active'] = true;
            foreach ($group['items'] as $index => &$item) {
                $item['is_active'] = $index === 0;
                $item['label'] = $index === 0 ? $group['key'].' voce' : null;
                $item['link_type'] = $index === 0 ? 'internal' : 'none';
                $item['target'] = $index === 0 ? 'center' : null;
                $item['external_url'] = null;
            }
            unset($item);
        }
        unset($group);
        $byKey = collect($menu['groups'])->keyBy('key');
        $menu['groups'] = [
            $byKey['prevention'],
            $byKey['know_remedic'],
            $byKey['information_health'],
            $byKey['territory'],
        ];
        [$menu['groups'][1]['items'][0], $menu['groups'][1]['items'][1], $menu['groups'][1]['items'][2]] = [$menu['groups'][1]['items'][2], $menu['groups'][1]['items'][0], $menu['groups'][1]['items'][1]];

        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $menu])
            ->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.0.key', 'prevention')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.1.items.0.key', 'plus_health_protocol');
        $publicGroups = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items.0.menu.groups');
        $this->assertSame(['prevention', 'know_remedic', 'information_health', 'territory'], array_column($publicGroups, 'key'));

        $menu['groups'][0]['is_active'] = false;
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $menu])->assertOk();
        $publicGroups = $this->getJson('/api/v1/public/navigation')->assertOk()->json('data.header.items.0.menu.groups');
        $this->assertFalse(collect($publicGroups)->contains('key', 'prevention'));
    }

    #[Test]
    public function center_mega_menu_requires_each_group_to_submit_exactly_its_three_structural_slots(): void
    {
        $this->actingAsWebAdmin();
        $menu = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->json('data.configuration.center_mega_menu');
        array_pop($menu['groups'][1]['items']);
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $menu])->assertUnprocessable();

        $menu = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->json('data.configuration.center_mega_menu');
        $menu['groups'][1]['items'][] = $menu['groups'][1]['items'][0];
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $menu])->assertUnprocessable();
    }

    #[Test]
    public function shared_action_catalog_exposes_master_actions_and_only_published_pages(): void
    {
        $this->createPage('center', 'il-centro', true);
        $this->createPage('why_choose_us', 'perche-remedic', false);
        SiteSetting::ensureSingleton()->update([
            'clinic_phone' => '+39 02 123456',
            'whatsapp_number' => '+39 333 1234567',
            'clinic_email' => 'info@remedic.test',
            'google_maps_url' => 'https://maps.example.test/remedic',
        ]);
        $this->actingAsWebAdmin();

        $targets = $this->getJson('/api/v1/admin/site-navigation')->assertOk()->json('data.targets');
        $keys = collect($targets)->pluck('key');
        $this->assertTrue($keys->contains('center'));
        $this->assertTrue($keys->contains('why_choose_us'));
        $this->assertSame($keys->count(), $keys->unique()->count());
        foreach (['booking', 'phone', 'whatsapp', 'map', 'email', 'external_url'] as $action) {
            $this->assertTrue($keys->contains($action));
        }

        $projection = app(SiteNavigationProjectionService::class);
        $this->assertSame('tel:+3902123456', $projection->target('phone')['href']);
        $this->assertSame('https://wa.me/393331234567?text=Buongiorno%20Remedic', $projection->target('whatsapp', null, ['whatsapp_message' => 'Buongiorno Remedic'])['href']);
        $this->assertSame('https://maps.example.test/remedic', $projection->target('map')['href']);
        $this->assertSame('mailto:info@remedic.test', $projection->target('email')['href']);
    }

    private function actingAsWebAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    private function createPage(string $key, string $slug, bool $published): void
    {
        $page = Page::query()->where('internal_key', $key)->orWhere('slug', $slug)->first();
        $attributes = ['internal_key' => $key, 'title' => $key, 'slug' => $slug, 'is_active' => true];
        $page ? $page->update($attributes) : Page::query()->create($attributes);
    }

    private function area(string $name, bool $visible): SpecializationWebProfile
    {
        $specialization = Specialization::query()->create(['name' => $name, 'slug' => str($name)->slug().'-master', 'is_active' => true]);

        return SpecializationWebProfile::query()->create(['specialization_id' => $specialization->id, 'slug' => str($name)->slug(), 'is_web_enabled' => $visible]);
    }
}
