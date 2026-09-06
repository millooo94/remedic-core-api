<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\CenterCoordinatesProvider;
use App\Services\LegacyWebContentImportService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CenterSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function public_read_does_not_create_the_singleton(): void
    {
        DB::table('site_settings')->delete();
        $this->getJson('/api/v1/public/site-settings')->assertOk();
        $this->assertDatabaseCount('site_settings', 0);
    }

    #[Test]
    public function admin_can_read_and_update_the_center_profile(): void
    {
        $this->actingAsRole(AdminRole::ADMIN);
        $this->getJson('/api/v1/management/settings/center')->assertOk();

        $this->putJson('/api/v1/management/settings/center', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('identity.clinic_name', 'Remedic Centro')
            ->assertJsonPath('contacts.whatsapp_number', '+39 095 000000')
            ->assertJsonPath('opening_hours.days.monday.1.start', '15:00')
            ->assertJsonPath('parking.formatted_address', 'Via Parcheggio 2, Acireale')
            ->assertJsonPath('parking.google_place_id', 'ChIJ-parking');

        $this->assertDatabaseHas('site_settings', [
            'id' => 1,
            'clinic_name' => 'Remedic Centro',
            'google_place_id' => 'ChIJ-test',
            'served_territory' => 'Provincia di Catania',
            'parking_google_place_id' => 'ChIJ-parking',
        ]);

        $this->getJson('/api/v1/management/settings/center')
            ->assertOk()
            ->assertJsonPath('territory.primary_city', 'Acireale')
            ->assertJsonPath('territory.primary_area', 'Etna')
            ->assertJsonPath('territory.served_areas', ['Acireale', 'Catania'])
            ->assertJsonPath('territory.served_territory', 'Provincia di Catania')
            ->assertJsonPath('territory.area_served_text', 'Sicilia orientale')
            ->assertJsonPath('parking.formatted_address', 'Via Parcheggio 2, Acireale')
            ->assertJsonPath('parking.latitude', 37.62)
            ->assertJsonMissingPath('parking.google_maps_url');
    }

    #[Test]
    public function seo_manager_cannot_access_center_settings(): void
    {
        $this->actingAsRole(AdminRole::SEO_MANAGER);
        $this->getJson('/api/v1/management/settings/center')->assertForbidden();
        $this->putJson('/api/v1/management/settings/center', $this->validPayload())->assertForbidden();
        $this->deleteJson('/api/v1/management/settings/center/logo')->assertForbidden();
    }

    #[Test]
    public function center_validation_rejects_coordinates_emails_urls_and_overlapping_hours(): void
    {
        $this->actingAsRole(AdminRole::ADMIN);
        $payload = $this->validPayload();
        $payload['contacts']['email'] = 'invalid';
        $payload['address']['latitude'] = 91;
        $payload['parking']['longitude'] = 181;
        $payload['links']['google_review_url'] = 'javascript:alert(1)';
        $payload['opening_hours']['days']['monday'][1]['start'] = '12:00';

        $this->putJson('/api/v1/management/settings/center', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'contacts.email', 'address.latitude', 'parking.longitude', 'links.google_review_url', 'opening_hours',
            ]);
    }

    #[Test]
    public function logo_can_be_uploaded_replaced_and_deleted_idempotently(): void
    {
        Storage::fake('public');
        $this->actingAsRole(AdminRole::ADMIN);

        $first = $this->post('/api/v1/management/settings/center/logo', [
            'logo' => UploadedFile::fake()->image('first.png'),
        ])->assertOk()->json('identity.logo_path');
        Storage::disk('public')->assertExists($first);

        $second = $this->post('/api/v1/management/settings/center/logo', [
            'logo' => UploadedFile::fake()->image('second.webp'),
        ])->assertOk()->json('identity.logo_path');
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->deleteJson('/api/v1/management/settings/center/logo')->assertOk()->assertJsonPath('identity.logo_path', null);
        Storage::disk('public')->assertMissing($second);
        $this->deleteJson('/api/v1/management/settings/center/logo')->assertOk();
    }

    #[Test]
    public function public_contract_exposes_structured_center_and_flat_aliases(): void
    {
        SiteSetting::ensureSingleton()->update([
            'clinic_name' => 'Centro canonico',
            'site_name' => 'Nome legacy',
            'google_maps_url' => 'https://maps.google.com/?q=1,2',
            'latitude' => 37.6,
            'longitude' => 15.1,
        ]);

        $this->getJson('/api/v1/public/site-settings')
            ->assertOk()
            ->assertJsonPath('data.settings.identity.clinic_name', 'Centro canonico')
            ->assertJsonPath('data.settings.clinic_name', 'Centro canonico')
            ->assertJsonPath('data.settings.address.latitude', 37.6)
            ->assertJsonPath('data.settings.maps_url', 'https://maps.google.com/?q=1,2');
    }

    #[Test]
    public function center_coordinates_prefer_database_and_fall_back_to_environment(): void
    {
        config()->set('services.geocoding.remedic_lat', 1.1);
        config()->set('services.geocoding.remedic_lng', 2.2);
        DB::table('site_settings')->delete();
        $provider = app(CenterCoordinatesProvider::class);
        $this->assertSame(['lat' => 1.1, 'lng' => 2.2], $provider->coordinates());

        $settings = SiteSetting::ensureSingleton();
        DB::table('site_settings')->where('id', $settings->id)->update(['latitude' => 37.61, 'longitude' => 15.16]);
        $this->assertSame(['lat' => 37.61, 'lng' => 15.16], $provider->coordinates());
    }

    #[Test]
    public function legacy_web_import_never_overwrites_center_master_fields(): void
    {
        SiteSetting::ensureSingleton()->update(['clinic_name' => 'Centro canonico']);
        config()->set('database.connections.legacy_backend', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        DB::purge('legacy_backend');
        $legacy = DB::connection('legacy_backend');
        $legacy->statement('CREATE TABLE site_settings (id INTEGER PRIMARY KEY, clinic_name TEXT, site_url TEXT, default_meta_title TEXT, default_meta_description TEXT, default_locality_phrase TEXT, default_og_image_path TEXT)');
        $legacy->table('site_settings')->insert(['id' => 99, 'clinic_name' => 'Nome legacy', 'site_url' => 'https://legacy.example']);

        app(LegacyWebContentImportService::class)->import(['site_settings'], false);

        $this->assertDatabaseHas('site_settings', ['id' => 1, 'clinic_name' => 'Centro canonico', 'site_url' => 'https://legacy.example']);
    }

    private function actingAsRole(AdminRole $role): User
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName($role->value, 'web'));
        Sanctum::actingAs($user);

        return $user;
    }

    private function validPayload(): array
    {
        $days = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], []);
        $days['monday'] = [['start' => '08:00', 'end' => '13:00'], ['start' => '15:00', 'end' => '20:00']];

        return [
            'identity' => ['clinic_name' => 'Remedic Centro', 'legal_company_name' => 'Remedic Srl', 'business_type' => 'MedicalClinic', 'vat_number' => 'IT 123', 'tax_code' => 'abc123'],
            'contacts' => ['phone' => '+39 095 111111', 'whatsapp_number' => '+39 095 000000', 'email' => 'info@remedic.it', 'pec_email' => 'remedic@pec.it', 'privacy_email' => 'privacy@remedic.it'],
            'address' => ['formatted_address' => 'Via Test 1, Acireale', 'street_name' => 'Via Test', 'street_number' => '1', 'postal_code' => '95024', 'city' => 'Acireale', 'province' => 'CT', 'region' => 'Sicilia', 'country' => 'Italia', 'country_code' => 'it', 'google_place_id' => 'ChIJ-test', 'latitude' => 37.61, 'longitude' => 15.16, 'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=37.61,15.16'],
            'opening_hours' => ['version' => 1, 'timezone' => 'Europe/Rome', 'days' => $days],
            'social' => ['facebook_url' => 'https://facebook.com/remedic', 'instagram_url' => null, 'linkedin_url' => null],
            'territory' => ['primary_city' => 'Acireale', 'primary_area' => 'Etna', 'served_areas' => ['Acireale', 'Catania'], 'served_territory' => 'Provincia di Catania', 'area_served_text' => 'Sicilia orientale'],
            'links' => ['google_review_url' => 'https://g.page/r/test'],
            'parking' => ['label' => 'Parcheggio clienti', 'formatted_address' => 'Via Parcheggio 2, Acireale', 'street_name' => 'Via Parcheggio', 'street_number' => '2', 'postal_code' => '95024', 'city' => 'Acireale', 'province' => 'CT', 'region' => 'Sicilia', 'country' => 'Italia', 'country_code' => 'it', 'google_place_id' => 'ChIJ-parking', 'latitude' => 37.62, 'longitude' => 15.17, 'description' => 'Ingresso laterale'],
        ];
    }
}
