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
    public function public_projection_resolves_only_published_targets_and_keeps_fixed_actions(): void
    {
        $this->createPage('center', 'il-centro', true);
        Page::query()->where('internal_key', 'contact')->update(['is_active' => true, 'published_at' => null]);
        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->where('internal_key', 'diagnostics_index')->update(['is_active' => true, 'published_at' => now()->subMinute()]);

        $this->getJson('/api/v1/public/navigation')->assertOk()
            ->assertJsonPath('data.header.items.0.key', 'center_menu')
            ->assertJsonPath('data.header.items.1.key', 'diagnostics')
            ->assertJsonPath('data.header.items.1.href', '/diagnostica')
            ->assertJsonMissingPath('data.header.items.2')
            ->assertJsonPath('data.header.reserved_area.action', 'reserved_area')
            ->assertJsonPath('data.header.booking.action', 'booking')
            ->assertJsonPath('data.header.items.0.menu.groups.0.items.0.href', '/il-centro');
    }

    #[Test]
    public function center_mega_menu_rejects_cross_group_targets_and_preserves_editorial_copy(): void
    {
        $this->actingAsWebAdmin();
        $configuration = $this->getJson('/api/v1/admin/site-navigation')->json('data.configuration.center_mega_menu');
        $configuration['groups']['territory']['items'][0]['target'] = 'center';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])->assertUnprocessable();

        $configuration['groups']['territory']['items'][0]['target'] = 'conventions_network';
        [$configuration['groups']['know_remedic']['items'][0], $configuration['groups']['know_remedic']['items'][1]] = [$configuration['groups']['know_remedic']['items'][1], $configuration['groups']['know_remedic']['items'][0]];
        $configuration['groups']['know_remedic']['items'][0]['description'] = 'Una descrizione editoriale.';
        $configuration['promo']['title'] = 'Scopri il centro';
        $this->putJson('/api/v1/admin/site-navigation/center-mega-menu', ['center_mega_menu' => $configuration])->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.groups.know_remedic.items.0.target', 'why_choose_us')
            ->assertJsonPath('data.configuration.center_mega_menu.groups.know_remedic.items.0.description', 'Una descrizione editoriale.')
            ->assertJsonPath('data.configuration.center_mega_menu.promo.title', 'Scopri il centro');
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
    public function center_section_icon_uses_a_managed_navigation_slot(): void
    {
        Storage::fake('public');
        $this->actingAsWebAdmin();

        $this->post('/api/v1/admin/site-navigation/center-mega-menu/sections/know_remedic/media', ['file' => UploadedFile::fake()->image('icon.png', 64, 64)])
            ->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.sections.0.icon_url', fn (string $url): bool => str_contains($url, 'site-navigation/center-mega-menu-icons/know_remedic'));
        $this->deleteJson('/api/v1/admin/site-navigation/center-mega-menu/sections/know_remedic/media')->assertOk()
            ->assertJsonPath('data.configuration.center_mega_menu.sections.0.icon_url', null);
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
            $attributes = ['internal_key' => $key, 'title' => $title, 'slug' => $slug, 'is_active' => true, 'published_at' => now()->subMinute()];
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

    private function actingAsWebAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    private function createPage(string $key, string $slug, bool $published): void
    {
        Page::query()->create(['internal_key' => $key, 'title' => $key, 'slug' => $slug, 'is_active' => true, 'published_at' => $published ? now()->subMinute() : null]);
    }

    private function area(string $name, bool $visible): SpecializationWebProfile
    {
        $specialization = Specialization::query()->create(['name' => $name, 'slug' => str($name)->slug().'-master', 'is_active' => true]);

        return SpecializationWebProfile::query()->create(['specialization_id' => $specialization->id, 'slug' => str($name)->slug(), 'is_web_enabled' => $visible]);
    }
}
