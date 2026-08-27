<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\Section;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceWebProfile;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Models\User;
use App\Services\EquipeContentService;
use App\Services\MedicalAreaContentService;
use App\Services\ServiceWebContentService;
use App\Support\MedicalAreas\MedicalAreaSectionDefinition;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MedicalAreaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function only_an_enabled_profile_with_an_active_master_is_public(): void
    {
        $master = $this->master(['is_active' => true, 'is_web_active' => true]);

        $this->getJson('/api/v1/public/aree-mediche')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/specializations')->assertOk()->assertJsonCount(0, 'data');

        $profile = SpecializationWebProfile::query()->create([
            'specialization_id' => $master->id,
            'slug' => 'cardiologia-clinica',
        ]);
        $this->assertFalse($profile->is_web_enabled);
        app(MedicalAreaContentService::class)->initializeSections($profile);
        $this->getJson('/api/v1/public/aree-mediche')->assertOk()->assertJsonCount(0, 'data');

        $profile->update(['is_web_enabled' => true]);
        $this->getJson('/api/v1/public/aree-mediche')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'cardiologia-clinica');

        $master->update(['is_active' => false]);
        $this->getJson('/api/v1/public/aree-mediche')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('specialization_web_profiles', ['id' => $profile->id, 'is_web_enabled' => true]);

        $master->update(['is_active' => true]);
        $this->getJson('/api/v1/public/aree-mediche')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function admin_upsert_keeps_master_immutable_persists_typed_sections_and_creates_slug_redirect(): void
    {
        $this->actingAsWebAdmin();
        $master = $this->master([
            'name' => 'Cardiologia', 'slug' => 'cardiologia-master', 'sort_order' => 12,
            'color_hex' => '#112233', 'icon_path' => 'icons/cardio.svg', 'featured_image_path' => 'areas/cardio.jpg',
        ]);

        $this->getJson("/api/v1/admin/aree-mediche/{$master->id}")->assertOk()
            ->assertJsonPath('master.name', 'Cardiologia')
            ->assertJsonPath('web_profile', null)
            ->assertJsonPath('status', 'not_configured');

        $invalid = $this->payload('cardiologia-pubblica') + ['name' => 'Mutata', 'service_ids' => [1]];
        $this->putJson("/api/v1/admin/aree-mediche/{$master->id}", $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'service_ids']);

        $created = $this->putJson("/api/v1/admin/aree-mediche/{$master->id}", $this->payload('cardiologia-pubblica'))
            ->assertOk()
            ->assertJsonPath('master.slug', 'cardiologia-master')
            ->assertJsonPath('web_profile.slug', 'cardiologia-pubblica')
            ->assertJsonPath('effective_public_visibility', true)
            ->assertJsonCount(7, 'web_profile.sections');

        $sectionIds = collect($created->json('web_profile.sections'))->pluck('id', 'key');
        $payload = $this->payload('cardiologia-nuova');
        $payload['sections'] = array_reverse($payload['sections']);
        $payload['sections'][5]['data'] = [
            'items' => [['icon_key' => 'heart', 'title' => 'Prevenzione', 'description' => 'Valutazione del rischio.']],
            'bottom_note' => 'Un percorso personalizzato.',
        ];
        $updated = $this->patchJson("/api/v1/admin/aree-mediche/{$master->id}", $payload)
            ->assertOk()
            ->assertJsonPath('web_profile.sections.0.key', 'equipe')
            ->assertJsonPath('web_profile.sections.5.data.items.0.title', 'Prevenzione');

        $this->assertSame(
            $sectionIds->sortKeys()->all(),
            collect($updated->json('web_profile.sections'))->pluck('id', 'key')->sortKeys()->all()
        );
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/aree-mediche/cardiologia-pubblica',
            'to_path' => '/aree-mediche/cardiologia-nuova',
            'source_type' => Redirect::SOURCE_TYPE_MEDICAL_AREA,
        ]);
        $master->refresh();
        $this->assertSame('Cardiologia', $master->name);
        $this->assertSame('cardiologia-master', $master->slug);
        $this->assertSame(12, $master->sort_order);
        $this->assertSame('#112233', $master->color_hex);
        $this->assertSame('icons/cardio.svg', $master->icon_path);
    }

    #[Test]
    public function public_detail_derives_services_equipe_and_faqs_and_omits_empty_or_hidden_items(): void
    {
        $master = $this->master();
        $profile = SpecializationWebProfile::query()->create([
            'specialization_id' => $master->id, 'slug' => 'cardiologia', 'is_web_enabled' => true,
        ]);
        app(MedicalAreaContentService::class)->initializeSections($profile);

        $this->getJson('/api/v1/public/aree-mediche/cardiologia')->assertOk()
            ->assertJsonMissing(['key' => 'services'])
            ->assertJsonMissing(['key' => 'equipe'])
            ->assertJsonMissing(['key' => 'faqs']);

        $categoryId = ServiceCategory::query()->value('id');
        $visibleService = Service::factory()->create(['category_id' => $categoryId, 'display_name' => 'ECG', 'is_active' => true, 'is_web_active' => true]);
        $hiddenService = Service::factory()->create(['category_id' => $categoryId, 'display_name' => 'Nascosta', 'is_active' => false, 'is_web_active' => true]);
        foreach ([[$visibleService, 'ecg'], [$hiddenService, 'nascosta']] as [$service, $slug]) {
            $serviceProfile = ServiceWebProfile::query()->create([
                'service_id' => $service->id,
                'public_slug' => $slug,
                'is_web_enabled' => true,
            ]);
            app(ServiceWebContentService::class)->initializeSections($serviceProfile);
        }
        $master->services()->attach($visibleService->id, ['is_primary' => true, 'sort_order' => 2]);
        $master->services()->attach($hiddenService->id, ['is_primary' => false, 'sort_order' => 1]);

        $professional = Professional::factory()->create([
            'honorific_prefix' => 'Dott.ssa', 'full_name' => 'Rossi Ada', 'email' => 'private@example.test',
            'iban' => 'IT60X0542811101000000123456', 'notes' => 'Riservato', 'birth_place' => 'Roma',
        ]);
        $professional->specializations()->attach($master->id, ['is_primary' => true, 'sort_order' => 0]);
        $professionalProfile = ProfessionalPublicProfile::query()->create([
            'professional_id' => $professional->id, 'slug' => 'ada-rossi', 'short_bio' => 'Cardiologa', 'is_web_enabled' => true,
        ]);
        app(EquipeContentService::class)->initializeSections($professionalProfile);
        $profile->faqs()->create(['question' => 'Serve una prescrizione?', 'answer' => 'No.', 'is_active' => true]);

        $response = $this->getJson('/api/v1/public/aree-mediche/cardiologia')->assertOk()
            ->assertJsonFragment(['key' => 'services'])
            ->assertJsonFragment(['key' => 'equipe'])
            ->assertJsonFragment(['key' => 'faqs'])
            ->assertJsonFragment(['name' => 'ECG'])
            ->assertJsonMissing(['name' => 'Nascosta'])
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.iban')
            ->assertJsonMissingPath('data.birth_place');

        $flattened = collect($response->json('data.sections'))->flatten()->all();
        $this->assertNotContains('private@example.test', $flattened);
        $this->assertNotContains('Riservato', $flattened);
        $sections = collect($response->json('data.sections'))->keyBy('key');
        $this->assertSame('/prestazioni/ecg', $sections->get('services')['data']['items'][0]['href']);
        $this->assertSame('/equipe/ada-rossi', $sections->get('equipe')['data']['items'][0]['href']);
        $this->getJson('/api/v1/public/specializations/cardiologia')->assertOk()->assertJsonPath('data.slug', 'cardiologia');
    }

    #[Test]
    public function list_home_settings_and_search_share_effective_visibility_and_canonical_hrefs(): void
    {
        $first = $this->master(['name' => 'Zeta']);
        $second = $this->master(['name' => 'Alfa', 'slug' => 'alfa-master']);
        foreach ([[$first, 'zeta', 2, true], [$second, 'alfa', 1, true]] as [$master, $slug, $order, $enabled]) {
            $profile = SpecializationWebProfile::query()->create([
                'specialization_id' => $master->id, 'slug' => $slug, 'list_sort_order' => $order, 'is_web_enabled' => $enabled,
            ]);
            app(MedicalAreaContentService::class)->initializeSections($profile);
        }

        $this->getJson('/api/v1/public/aree-mediche')->assertOk()->assertJsonPath('data.0.slug', 'alfa');
        $this->getJson('/api/v1/public/home')->assertOk()->assertJsonPath('data.specializations.0.slug', 'alfa');
        $this->getJson('/api/v1/public/site-settings')->assertOk()
            ->assertJsonPath('data.navigation.1.label', 'Aree mediche')
            ->assertJsonPath('data.footer_specializations.0.href', '/aree-mediche/alfa');
        $this->getJson('/api/v1/public/search?q=Alfa')->assertOk()
            ->assertJsonPath('data.results.0.type', 'medical_area')
            ->assertJsonPath('data.results.0.href', '/aree-mediche/alfa');
    }

    #[Test]
    public function referenced_master_delete_is_blocked_and_management_rename_preserves_master_slug(): void
    {
        $this->actingAsWebAdmin();
        $master = $this->master(['slug' => 'immutable']);
        $service = Service::factory()->create(['category_id' => ServiceCategory::query()->value('id')]);
        $master->services()->attach($service->id, ['is_primary' => true, 'sort_order' => 0]);

        $this->deleteJson("/api/v1/specializations/{$master->id}")
            ->assertStatus(409)
            ->assertJsonPath('dependencies.services', 1);
        $this->assertDatabaseHas('service_specialization', ['specialization_id' => $master->id, 'service_id' => $service->id]);

        $this->putJson("/api/v1/specializations/{$master->id}", [
            'name' => 'Nome rinominato', 'slug' => 'tentativo-modifica', 'sort_order' => 4,
        ])->assertOk()->assertJsonPath('slug', 'immutable')->assertJsonPath('sort_order', 4);
    }

    #[Test]
    public function backfill_ignores_empty_legacy_defaults_and_preserves_meaningful_sections_and_faqs(): void
    {
        $empty = $this->master(['name' => 'Vuota', 'slug' => 'vuota', 'is_web_active' => true, 'sort_order' => 9]);
        $meaningful = $this->master([
            'name' => 'Area legacy',
            'slug' => 'area-legacy',
            'short_description' => 'Descrizione reale',
            'intro_text' => 'Introduzione reale',
            'local_area_notes' => 'Nota legacy da preservare',
            'is_web_active' => true,
        ]);
        $legacySection = $meaningful->sections()->create([
            'key' => 'what_is', 'title' => 'Ambito legacy', 'content' => 'Testo sezione', 'sort_order' => 4,
        ]);
        $legacyFaq = $meaningful->faqs()->create([
            'question' => 'Domanda legacy?', 'answer' => 'Risposta legacy.', 'sort_order' => 0,
        ]);

        $backfill = require database_path('migrations/2026_08_24_101000_backfill_specialization_web_profiles_from_specializations.php');
        $backfill->up();
        $reparent = require database_path('migrations/2026_08_24_102000_reparent_specialization_sections_and_faqs_to_web_profiles.php');
        $reparent->up();

        $this->assertDatabaseMissing('specialization_web_profiles', ['specialization_id' => $empty->id]);
        $profile = SpecializationWebProfile::query()->where('specialization_id', $meaningful->id)->firstOrFail();
        $this->assertTrue($profile->is_web_enabled);
        $this->assertSame('Nota legacy da preservare', $profile->legacy_content['local_area_notes']);
        $this->assertDatabaseHas('sections', [
            'id' => $legacySection->id,
            'sectionable_type' => SpecializationWebProfile::class,
            'sectionable_id' => $profile->id,
            'key' => 'scope',
        ]);
        $this->assertDatabaseHas('faq_items', [
            'id' => $legacyFaq->id,
            'faqable_type' => SpecializationWebProfile::class,
            'faqable_id' => $profile->id,
        ]);
        $this->assertSame(7, Section::query()
            ->where('sectionable_type', SpecializationWebProfile::class)
            ->where('sectionable_id', $profile->id)
            ->whereIn('key', MedicalAreaSectionDefinition::keys())
            ->count());
    }

    #[Test]
    public function primary_normalization_repairs_professionals_without_changing_memberships_or_service_primary(): void
    {
        $first = $this->master(['name' => 'Prima area', 'slug' => 'prima-area']);
        $second = $this->master(['name' => 'Seconda area', 'slug' => 'seconda-area']);
        $professional = Professional::factory()->create();
        $professional->specializations()->attach($first->id, ['is_primary' => false, 'sort_order' => 5]);
        $professional->specializations()->attach($second->id, ['is_primary' => false, 'sort_order' => 1]);
        $service = Service::factory()->create(['category_id' => ServiceCategory::query()->value('id')]);
        $service->specializations()->attach($first->id, ['is_primary' => true, 'sort_order' => 0]);
        $service->specializations()->attach($second->id, ['is_primary' => false, 'sort_order' => 1]);
        $serviceFlags = $service->specializations()->get()->mapWithKeys(
            fn ($area) => [$area->id => (bool) $area->pivot->is_primary]
        )->all();

        $migration = require database_path('migrations/2026_08_24_103000_normalize_professional_primary_and_add_equipe_services_section.php');
        $migration->up();

        $links = $professional->specializations()->get();
        $this->assertCount(2, $links);
        $this->assertSame(1, $links->where('pivot.is_primary', true)->count());
        $this->assertTrue((bool) $links->firstWhere('id', $second->id)->pivot->is_primary);
        $this->assertSame($serviceFlags, $service->fresh()->specializations()->get()->mapWithKeys(
            fn ($area) => [$area->id => (bool) $area->pivot->is_primary]
        )->all());

        $this->actingAsWebAdmin();
        $this->putJson("/api/v1/professionals/{$professional->id}", [
            'specialization_ids' => [$first->id, $second->id],
        ])->assertOk();

        $updatedLinks = $professional->fresh()->specializations()->get();
        $this->assertCount(2, $updatedLinks);
        $this->assertSame(1, $updatedLinks->where('pivot.is_primary', true)->count());
        $this->assertTrue((bool) $updatedLinks->firstWhere('id', $first->id)->pivot->is_primary);
    }

    private function master(array $attributes = []): Specialization
    {
        static $counter = 0;
        $counter++;

        return Specialization::query()->create(array_merge([
            'name' => 'Cardiologia '.$counter,
            'slug' => 'cardiologia-master-'.$counter,
            'is_active' => true,
            'is_web_active' => true,
            'sort_order' => 0,
        ], $attributes));
    }

    private function payload(string $slug): array
    {
        $definitions = [
            ['hero', 'Hero / Area medica'],
            ['scope', 'Di cosa si occupa'],
            ['when_useful', 'Quando è utile una visita'],
            ['visit_process', 'Cosa succede durante la visita'],
            ['services', 'Prestazioni'],
            ['faqs', 'Domande frequenti'],
            ['equipe', 'Équipe'],
        ];

        return [
            'slug' => $slug,
            'short_description' => 'Descrizione breve',
            'is_web_enabled' => true,
            'list_sort_order' => 3,
            'is_local_seo_enabled' => true,
            'robots' => 'index,follow',
            'sections' => collect($definitions)->map(fn ($definition) => [
                'key' => $definition[0], 'title' => $definition[1], 'intro' => null, 'is_active' => true, 'data' => [],
            ])->all(),
            'faqs' => [],
        ];
    }

    private function actingAsWebAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
