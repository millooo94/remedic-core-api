<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\FaqItem;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\Section;
use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Models\User;
use App\Services\EquipeContentService;
use App\Services\MedicalAreaContentService;
use App\Services\ServiceWebContentService;
use App\Support\Services\ServiceSectionDefinition;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceWebProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function profile_requires_a_service_is_one_to_one_and_defaults_to_disabled(): void
    {
        $service = $this->masterService('Master');
        $profile = ServiceWebProfile::query()->create([
            'service_id' => $service->id,
            'public_slug' => 'master-pubblico',
        ]);
        $this->assertFalse($profile->is_web_enabled);

        foreach ([
            ['service_id' => $service->id, 'public_slug' => 'secondo-profilo'],
            ['service_id' => $this->masterService('Altro master')->id, 'public_slug' => 'master-pubblico'],
            ['service_id' => 999999, 'public_slug' => 'senza-master'],
        ] as $invalid) {
            try {
                ServiceWebProfile::query()->create($invalid);
                $this->fail('Il vincolo del profilo Web avrebbe dovuto rifiutare il record.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('service_web_profiles', 1);
    }

    #[Test]
    public function effective_visibility_requires_only_active_master_and_enabled_profile(): void
    {
        $legacyOnly = $this->masterService('Solo flag legacy', [
            'is_active' => true,
            'is_web_active' => true,
            'is_featured' => true,
        ]);
        $visible = $this->profiledService('Visibile', 'visibile', true, true);
        $inactiveMaster = $this->profiledService('Master inattivo', 'master-inattivo', false, true);
        $disabledProfile = $this->profiledService('Profilo spento', 'profilo-spento', true, false);
        $withoutAreaOrProfessional = $this->profiledService('Senza dipendenze', 'senza-dipendenze', true, true);

        $response = $this->getJson('/api/v1/public/prestazioni')->assertOk();
        $this->assertSame(['senza-dipendenze', 'visibile'], collect($response->json('data'))->pluck('slug')->all());
        $response->assertJsonMissing(['slug' => $legacyOnly->slug])
            ->assertJsonMissing(['slug' => $inactiveMaster->webProfile->public_slug])
            ->assertJsonMissing(['slug' => $disabledProfile->webProfile->public_slug]);

        $this->getJson('/api/v1/public/prestazioni/visibile')->assertOk();
        $this->getJson('/api/v1/public/prestazioni/'.$visible->slug)->assertNotFound();
        $this->getJson('/api/v1/public/services/visibile')->assertOk()->assertJsonPath('data.slug', 'visibile');
        $this->getJson('/api/v1/public/services/'.$visible->slug)->assertOk()->assertJsonPath('data.slug', 'visibile');
        $this->getJson('/api/v1/public/services?featured=1')->assertOk()
            ->assertJsonMissing(['slug' => $legacyOnly->slug]);
        $this->getJson('/api/v1/public/prestazioni/senza-dipendenze')->assertOk();
        $this->getJson('/api/v1/public/home')->assertOk()
            ->assertJsonPath('data.services.0.slug', 'senza-dipendenze')
            ->assertJsonPath('data.services.1.slug', 'visibile');
        $this->getJson('/api/v1/public/search?q=Visibile')->assertOk()
            ->assertJsonPath('data.results.0.type', 'service')
            ->assertJsonPath('data.results.0.href', '/prestazioni/visibile');
        $this->assertFalse($legacyOnly->isEffectivelyVisible());
        $this->assertTrue($withoutAreaOrProfessional->isEffectivelyVisible());

        $visible->delete();
        $archived = Service::withTrashed()->findOrFail($visible->id);
        $this->assertFalse($archived->isEffectivelyVisible());
        $this->getJson('/api/v1/public/prestazioni/visibile')->assertNotFound();
        $archived->restore();
        $this->assertTrue($archived->refresh()->isEffectivelyVisible());
    }

    #[Test]
    public function admin_upsert_preserves_section_and_faq_ids_and_keeps_master_read_only(): void
    {
        $this->actingAsAdmin();
        $service = $this->masterService('Visita cardiologica', [
            'canonical_name' => 'Visita cardiologica master',
            'slug' => 'cardiologia-master',
            'description' => 'Descrizione legacy da preservare',
            'importo_prestazione' => 120.50,
            'default_duration_minutes' => 30,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/admin/prestazioni')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.web_profile', null)
            ->assertJsonPath('data.0.status', 'not_configured');

        $invalid = $this->payload('visita-cardiologica') + [
            'display_name' => 'Tentativo',
            'importo_prestazione' => 1,
            'default_duration_minutes' => 1,
            'professional_ids' => [1],
            'specialization_ids' => [1],
            'social_image_path' => 'override.jpg',
        ];
        $this->putJson("/api/v1/admin/prestazioni/{$service->id}", $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'display_name', 'importo_prestazione', 'default_duration_minutes',
                'professional_ids', 'specialization_ids', 'social_image_path',
            ]);

        $payload = $this->payload('visita-cardiologica');
        $payload['faqs'] = [[
            'question' => 'Serve la prescrizione?',
            'answer' => 'No.',
            'is_active' => true,
            'is_structured_data' => true,
        ]];
        $created = $this->putJson("/api/v1/admin/prestazioni/{$service->id}", $payload)
            ->assertOk()
            ->assertJsonPath('service.price', '120.50')
            ->assertJsonPath('service.duration_minutes', 30)
            ->assertJsonPath('web_profile.public_slug', 'visita-cardiologica')
            ->assertJsonCount(8, 'web_profile.sections')
            ->assertJsonCount(1, 'web_profile.faqs');

        $sectionIds = collect($created->json('web_profile.sections'))->pluck('id', 'key');
        $faqId = (int) $created->json('web_profile.faqs.0.id');
        $updatedPayload = $payload;
        $updatedPayload['public_slug'] = 'visita-cardiologica-clinica';
        $updatedPayload['sections'] = array_reverse($updatedPayload['sections']);
        $updatedPayload['faqs'][0]['id'] = $faqId;
        $updatedPayload['faqs'][0]['answer'] = 'Non è obbligatoria.';

        $updated = $this->patchJson("/api/v1/admin/services/{$service->id}", $updatedPayload)
            ->assertOk()
            ->assertJsonPath('web_profile.sections.0.key', 'hero')
            ->assertJsonPath('web_profile.faqs.0.id', $faqId);

        $this->assertSame(
            $sectionIds->sortKeys()->all(),
            collect($updated->json('web_profile.sections'))->pluck('id', 'key')->sortKeys()->all(),
        );
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/prestazioni/visita-cardiologica',
            'to_path' => '/prestazioni/visita-cardiologica-clinica',
            'source_type' => Redirect::SOURCE_TYPE_SERVICE_WEB_PROFILE,
        ]);
        $this->assertSame('Descrizione legacy da preservare', $service->refresh()->description);
        $this->assertSame('Visita cardiologica', $service->display_name);
    }

    #[Test]
    public function admin_index_filters_services_by_master_specialization_and_professional(): void
    {
        $this->actingAsAdmin();
        $cardiology = Specialization::query()->create(['name' => 'Cardiologia filtro', 'slug' => 'cardiologia-filtro', 'is_active' => true]);
        $dermatology = Specialization::query()->create(['name' => 'Dermatologia filtro', 'slug' => 'dermatologia-filtro', 'is_active' => true]);
        $doctor = Professional::factory()->create(['full_name' => 'Ada Rossi', 'is_active' => true]);
        $matching = $this->masterService('Visita cardiologica');
        $other = $this->masterService('Visita dermatologica');
        $matching->specializations()->attach($cardiology->id, ['is_primary' => true, 'sort_order' => 0]);
        $other->specializations()->attach($dermatology->id, ['is_primary' => true, 'sort_order' => 0]);
        $matching->professionalServices()->create(['professional_id' => $doctor->id, 'is_active' => true]);

        $this->getJson("/api/v1/admin/prestazioni?specialization_id={$cardiology->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
        $this->getJson("/api/v1/admin/prestazioni?professional_id={$doctor->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->id);
    }

    #[Test]
    public function admin_index_filters_services_by_master_and_web_enabled_state(): void
    {
        $this->actingAsAdmin();
        $active = $this->profiledService('Attiva abilitata', 'attiva-abilitata', true, true);
        $this->profiledService('Attiva non abilitata', 'attiva-non-abilitata', true, false);
        $this->profiledService('Inattiva abilitata', 'inattiva-abilitata', false, true);

        $this->getJson('/api/v1/admin/prestazioni?is_active=1&is_web_enabled=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonMissingPath('data.0.web_profile.list_sort_order');
    }

    #[Test]
    public function public_services_are_ordered_by_master_display_name_not_legacy_web_order(): void
    {
        $this->profiledService('Zeta', 'zeta', true, true);
        $alpha = $this->profiledService('Alfa', 'alfa', true, true);
        $this->getJson('/api/v1/public/prestazioni')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'alfa')
            ->assertJsonPath('data.1.slug', 'zeta');
    }

    #[Test]
    public function public_detail_derives_price_area_professionals_and_faqs_and_omits_empty_sections(): void
    {
        $area = Specialization::query()->create([
            'name' => 'Cardiologia',
            'slug' => 'cardiologia-master',
            'is_active' => true,
            'icon_path' => 'areas/cardio.svg',
        ]);
        $areaProfile = SpecializationWebProfile::query()->create([
            'specialization_id' => $area->id,
            'slug' => 'cardiologia',
            'is_web_enabled' => true,
        ]);
        app(MedicalAreaContentService::class)->initializeSections($areaProfile);

        $service = $this->profiledService('ECG', 'ecg', true, true, [
            'importo_prestazione' => 99.90,
            'default_duration_minutes' => 20,
            'featured_image_path' => 'services/ecg.jpg',
        ]);
        $service->specializations()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);
        $professional = Professional::factory()->create([
            'honorific_prefix' => 'Dott.ssa',
            'full_name' => 'Ada Rossi',
            'is_active' => true,
            'email' => 'privata@example.test',
            'notes' => 'Dato riservato',
        ]);
        $professional->specializations()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);
        $professionalProfile = ProfessionalPublicProfile::query()->create([
            'professional_id' => $professional->id,
            'slug' => 'ada-rossi',
            'short_bio' => 'Cardiologa',
            'is_web_enabled' => true,
        ]);
        app(EquipeContentService::class)->initializeSections($professionalProfile);
        $service->professionalServices()->create([
            'professional_id' => $professional->id,
            'is_active' => true,
            'is_visible_public' => true,
            'is_bookable_online' => false,
        ]);
        $service->webProfile->faqs()->create([
            'question' => 'Quanto dura?',
            'answer' => 'Circa venti minuti.',
            'is_active' => true,
            'is_structured_data' => true,
        ]);

        $response = $this->getJson('/api/v1/public/prestazioni/ecg')->assertOk()
            ->assertJsonPath('data.href', '/prestazioni/ecg')
            ->assertJsonPath('data.localized_routes.0', ['locale' => 'it', 'href' => '/prestazioni/ecg'])
            ->assertJsonPath('data.available_locales', ['it'])
            ->assertJsonPath('data.price', '99.90')
            ->assertJsonPath('data.duration_minutes', 20)
            ->assertJsonPath('data.primary_area.slug', 'cardiologia')
            ->assertJsonFragment(['key' => 'price'])
            ->assertJsonFragment(['key' => 'faqs'])
            ->assertJsonFragment(['key' => 'equipe'])
            ->assertJsonFragment(['slug' => 'ada-rossi'])
            ->assertJsonMissing(['email' => 'privata@example.test'])
            ->assertJsonMissing(['notes' => 'Dato riservato'])
            ->assertJsonMissingPath('data.is_bookable_online');
        $this->assertSame(ServiceSectionDefinition::keys(), collect($response->json('data.sections'))->pluck('key')->all());
        $sections = collect($response->json('data.sections'))->keyBy('key');
        $this->assertSame('/equipe/ada-rossi', $sections->get('equipe')['data']['items'][0]['href']);

        $empty = $this->profiledService('Prestazione vuota', 'prestazione-vuota', true, true);
        $emptyResponse = $this->getJson('/api/v1/public/prestazioni/prestazione-vuota')->assertOk();
        $emptyKeys = collect($emptyResponse->json('data.sections'))->pluck('key');
        $this->assertFalse($emptyKeys->contains('price'));
        $this->assertFalse($emptyKeys->contains('faqs'));
        $this->assertFalse($emptyKeys->contains('equipe'));
    }

    #[Test]
    public function backfill_ignores_legacy_defaults_and_preserves_meaningful_content_disabled(): void
    {
        $empty = $this->masterService('Vuota', [
            'slug' => 'vuota',
            'is_web_active' => true,
            'sort_order' => 8,
        ]);
        $meaningful = $this->masterService('Legacy', [
            'slug' => 'legacy',
            'description' => 'Contenuto reale',
            'preparation_notes' => 'Preparazione reale',
            'is_web_active' => true,
        ]);
        $legacySection = Section::query()->create([
            'sectionable_type' => Service::class,
            'sectionable_id' => $meaningful->id,
            'key' => 'when',
            'title' => 'Quando',
            'content' => 'Quando richiederla',
            'sort_order' => 4,
            'is_active' => true,
        ]);
        $legacyFaq = FaqItem::query()->create([
            'faqable_type' => Service::class,
            'faqable_id' => $meaningful->id,
            'question' => 'FAQ legacy?',
            'answer' => 'Risposta.',
            'is_active' => true,
        ]);

        Schema::table('service_web_profiles', fn (Blueprint $table) => $table->integer('list_sort_order')->default(0));
        (require database_path('migrations/2026_08_24_105000_backfill_service_web_profiles_from_services.php'))->up();
        (require database_path('migrations/2026_08_24_106000_reparent_service_sections_and_faqs_to_web_profiles.php'))->up();

        $this->assertDatabaseMissing('service_web_profiles', ['service_id' => $empty->id]);
        $profile = ServiceWebProfile::query()->where('service_id', $meaningful->id)->firstOrFail();
        $this->assertFalse($profile->is_web_enabled);
        $this->assertSame('Contenuto reale', $profile->legacy_content['description']);
        $this->assertDatabaseHas('sections', [
            'id' => $legacySection->id,
            'sectionable_type' => ServiceWebProfile::class,
            'sectionable_id' => $profile->id,
            'key' => 'when_to_request',
        ]);
        $this->assertDatabaseHas('faq_items', [
            'id' => $legacyFaq->id,
            'faqable_type' => ServiceWebProfile::class,
            'faqable_id' => $profile->id,
        ]);
        $this->assertSame(8, $profile->sections()->whereIn('key', ServiceSectionDefinition::keys())->count());
    }

    #[Test]
    public function archive_preserves_profile_restore_republishes_and_hard_delete_is_only_for_unused_services(): void
    {
        $this->actingAsAdmin();
        $service = $this->profiledService('Archiviabile', 'archiviabile', true, true);
        $profileId = $service->webProfile->id;

        $this->deleteJson("/api/v1/services/{$service->id}")->assertNoContent();
        $this->assertSoftDeleted('services', ['id' => $service->id]);
        $this->assertDatabaseHas('service_web_profiles', ['id' => $profileId, 'is_web_enabled' => true]);
        $this->getJson('/api/v1/public/prestazioni/archiviabile')->assertNotFound();

        $this->postJson("/api/v1/services/{$service->id}/restore")->assertOk();
        $this->getJson('/api/v1/public/prestazioni/archiviabile')->assertOk();
        $this->deleteJson("/api/v1/services/{$service->id}/force")
            ->assertConflict()->assertJsonPath('dependencies.web_profile', 1);

        $unused = $this->masterService('Inutilizzata');
        $this->deleteJson("/api/v1/services/{$unused->id}")->assertNoContent();
        $this->deleteJson("/api/v1/services/{$unused->id}/force")->assertNoContent();
        $this->assertDatabaseMissing('services', ['id' => $unused->id]);
    }

    #[Test]
    public function service_management_and_web_endpoints_require_manage_services(): void
    {
        $service = $this->masterService('Protetta da RBAC');
        $editor = User::factory()->create(['role' => UserRole::Admin]);
        $editor->givePermissionTo(AdminPermission::VIEW_BACKOFFICE->value);
        Sanctum::actingAs($editor);

        $this->getJson('/api/v1/admin/prestazioni')->assertForbidden();
        $this->getJson('/api/v1/services')->assertForbidden();
        $this->postJson("/api/v1/services/{$service->id}/image", [])->assertForbidden();

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/prestazioni')->assertOk();
        $this->getJson('/api/v1/services')->assertOk();
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    private function profiledService(
        string $name,
        string $publicSlug,
        bool $active,
        bool $enabled,
        array $master = [],
    ): Service {
        $service = $this->masterService($name, array_merge([
            'is_active' => $active,
            'is_web_active' => ! $enabled,
            'importo_prestazione' => null,
            'default_duration_minutes' => null,
        ], $master));
        $profile = ServiceWebProfile::query()->create([
            'service_id' => $service->id,
            'public_slug' => $publicSlug,
            'is_web_enabled' => $enabled,
        ]);
        app(ServiceWebContentService::class)->initializeSections($profile);

        return $service->refresh()->load('webProfile');
    }

    private function masterService(string $name, array $attributes = []): Service
    {
        return Service::query()->create(array_merge([
            'category_id' => null,
            'canonical_name' => $name,
            'display_name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(8)),
            'description' => null,
            'default_duration_minutes' => null,
            'is_active' => true,
            'is_web_active' => false,
        ], $attributes));
    }

    private function payload(string $slug): array
    {
        return [
            'public_slug' => $slug,
            'short_description' => 'Descrizione pubblica breve.',
            'is_web_enabled' => true,
            'is_local_seo_enabled' => true,
            'robots' => 'index,follow',
            'sections' => collect(ServiceSectionDefinition::keys())->map(fn (string $key) => [
                'key' => $key,
                'title' => $key === 'hero' ? null : ServiceSectionDefinition::DEFINITIONS[$key],
                'intro' => null,
                'is_active' => true,
                'data' => match ($key) {
                    'what_is' => ['items' => [], 'bottom_note' => null],
                    'when_to_request' => ['groups' => []],
                    'procedure' => [
                        'steps' => [],
                        'additional_info_enabled' => false,
                        'additional_info_title' => null,
                        'additional_info_text' => null,
                        'additional_info_items' => [],
                    ],
                    'preparation' => ['items' => [], 'info_box_enabled' => false, 'info_box_text' => null],
                    default => [],
                },
            ])->all(),
            'faqs' => [],
        ];
    }
}
