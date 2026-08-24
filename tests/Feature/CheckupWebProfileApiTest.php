<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use App\Models\Page;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Models\User;
use App\Services\CheckupWebContentService;
use App\Services\EquipeContentService;
use App\Services\MedicalAreaContentService;
use App\Services\ServiceWebContentService;
use App\Support\Checkups\CheckupSectionDefinition;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckupWebProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function profile_is_optional_one_to_one_restricted_and_disabled_by_default(): void
    {
        $checkup = Checkup::factory()->create();
        $profile = CheckupWebProfile::query()->create(['checkup_id' => $checkup->id, 'public_slug' => 'prevenzione']);
        $this->assertFalse($profile->is_web_enabled);

        foreach ([
            ['checkup_id' => $checkup->id, 'public_slug' => 'duplicato'],
            ['checkup_id' => Checkup::factory()->create()->id, 'public_slug' => 'prevenzione'],
            ['checkup_id' => 999999, 'public_slug' => 'orfano'],
        ] as $invalid) {
            try {
                CheckupWebProfile::query()->create($invalid);
                $this->fail('Il vincolo del profilo Web avrebbe dovuto rifiutare il record.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(QueryException::class);
        $checkup->forceDelete();
    }

    #[Test]
    public function public_visibility_is_independent_from_operational_availability_and_handles_archived_services(): void
    {
        $empty = $this->profiledCheckup('Check-up senza componenti', 'senza-componenti', true, true);
        $service = $this->service('Prestazione archiviata');
        $archived = $this->profiledCheckup('Check-up archiviato nei componenti', 'componente-archiviato', true, true, [$service]);
        $service->delete();
        $this->profiledCheckup('Master inattivo', 'master-inattivo', false, true);
        $this->profiledCheckup('Profilo disabilitato', 'profilo-disabilitato', true, false);

        $response = $this->getJson('/api/v1/public/check-up')->assertOk();
        $this->assertSame(['componente-archiviato', 'senza-componenti'], collect($response->json('data'))->pluck('slug')->all());
        $response->assertJsonFragment(['slug' => $empty->webProfile->public_slug, 'is_operationally_available' => false]);
        $this->getJson('/api/v1/public/check-up/master-inattivo')->assertNotFound();

        $this->getJson('/api/v1/public/check-up/componente-archiviato')->assertOk()
            ->assertJsonPath('data.is_operationally_available', false)
            ->assertJsonPath('data.included_services.0.is_archived', true)
            ->assertJsonPath('data.included_services.0.href', null)
            ->assertJsonFragment(['key' => 'included_services']);
        $this->assertTrue($archived->refresh()->load('webProfile')->isEffectivelyVisible());
    }

    #[Test]
    public function admin_upsert_preserves_ids_redirects_slug_and_cannot_modify_master_fields(): void
    {
        $this->actingAsAdmin();
        $service = $this->service('ECG');
        $checkup = Checkup::factory()->create([
            'display_name' => 'Cuore completo', 'price_amount' => 199.90,
            'indicative_duration_minutes' => 75, 'organizational_notes' => 'Riservato',
        ]);
        $checkup->items()->create(['service_id' => $service->id, 'sort_order' => 0]);

        $this->getJson('/api/v1/admin/check-up')->assertOk()
            ->assertJsonPath('data.0.web_profile', null)->assertJsonPath('data.0.status', 'not_configured');

        $invalid = $this->payload('cuore-completo') + [
            'display_name' => 'Tentativo', 'price_amount' => 1,
            'indicative_duration_minutes' => 1, 'organizational_notes' => 'Leak',
            'service_ids' => [999], 'professional_ids' => [999],
        ];
        $this->putJson("/api/v1/admin/check-up/{$checkup->id}", $invalid)->assertUnprocessable()
            ->assertJsonValidationErrors(['display_name', 'price_amount', 'indicative_duration_minutes', 'organizational_notes', 'service_ids', 'professional_ids']);

        $payload = $this->payload('cuore-completo');
        $payload['faqs'] = [[
            'question' => 'Serve il digiuno?', 'answer' => 'No.',
            'is_active' => true, 'is_structured_data' => true,
        ]];
        $created = $this->putJson("/api/v1/admin/check-up/{$checkup->id}", $payload)->assertOk()
            ->assertJsonPath('checkup.price', '199.90')->assertJsonPath('checkup.duration_minutes', 75)
            ->assertJsonCount(10, 'web_profile.sections')->assertJsonCount(1, 'web_profile.faqs');
        $sectionIds = collect($created->json('web_profile.sections'))->pluck('id', 'key');
        $faqId = (int) $created->json('web_profile.faqs.0.id');

        $payload['public_slug'] = 'cuore-completo-plus';
        $payload['sections'] = array_reverse($payload['sections']);
        $payload['faqs'][0]['id'] = $faqId;
        $payload['faqs'][0]['answer'] = 'Solo se indicato.';
        $updated = $this->patchJson("/api/v1/admin/check-up/{$checkup->id}", $payload)->assertOk()
            ->assertJsonPath('web_profile.sections.0.key', 'related_checkups')
            ->assertJsonPath('web_profile.faqs.0.id', $faqId);
        $this->assertSame($sectionIds->sortKeys()->all(), collect($updated->json('web_profile.sections'))->pluck('id', 'key')->sortKeys()->all());
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/check-up/cuore-completo', 'to_path' => '/check-up/cuore-completo-plus',
            'source_type' => Redirect::SOURCE_TYPE_CHECKUP_WEB_PROFILE,
        ]);
        $this->assertSame('Riservato', $checkup->refresh()->organizational_notes);
    }

    #[Test]
    public function inactive_and_empty_derived_sections_are_omitted_and_legacy_pages_are_untouched(): void
    {
        Page::query()->create([
            'title' => 'Check-up legacy', 'slug' => 'check-up-legacy', 'template' => 'default',
            'is_active' => true, 'published_at' => now(),
        ]);
        $checkup = $this->profiledCheckup('Nuovo Check-up', 'nuovo-check-up', true, true);
        $before = Page::query()->findOrFail(1)->toArray();

        $response = $this->getJson('/api/v1/public/check-up/nuovo-check-up')->assertOk();
        $keys = collect($response->json('data.sections'))->pluck('key');
        $this->assertFalse($keys->contains('included_services'));
        $this->assertFalse($keys->contains('faqs'));
        $this->assertFalse($keys->contains('equipe'));
        $this->assertFalse($keys->contains('related_checkups'));
        $this->getJson('/api/v1/public/check-up/check-up-legacy')->assertNotFound();
        $this->assertSame($before, Page::query()->findOrFail(1)->toArray());

        $profile = $checkup->webProfile;
        $profile->sections()->where('key', 'what_is')->update(['is_active' => false]);
        $this->getJson('/api/v1/public/check-up/nuovo-check-up')->assertOk()->assertJsonMissing(['key' => 'what_is']);
    }

    #[Test]
    public function public_projections_expose_links_only_for_effectively_visible_related_entities(): void
    {
        $area = Specialization::query()->create(['name' => 'Cardiologia', 'slug' => 'cardiologia-master', 'icon_path' => 'areas/cardio.svg', 'is_active' => true]);
        $areaProfile = SpecializationWebProfile::query()->create(['specialization_id' => $area->id, 'slug' => 'cardiologia', 'is_web_enabled' => true]);
        app(MedicalAreaContentService::class)->initializeSections($areaProfile);
        $service = $this->service('ECG pubblico');
        $service->specializations()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);
        $serviceProfile = ServiceWebProfile::query()->create(['service_id' => $service->id, 'public_slug' => 'ecg-pubblico', 'is_web_enabled' => true]);
        app(ServiceWebContentService::class)->initializeSections($serviceProfile);
        $professional = Professional::factory()->create(['honorific_prefix' => 'Dott.ssa', 'full_name' => 'Ada Rossi', 'is_active' => true, 'email' => 'privata@example.test', 'notes' => 'Riservato']);
        $professional->specializations()->attach($area->id, ['is_primary' => true, 'sort_order' => 0]);
        $professionalProfile = ProfessionalPublicProfile::query()->create(['professional_id' => $professional->id, 'slug' => 'ada-rossi', 'is_web_enabled' => true]);
        app(EquipeContentService::class)->initializeSections($professionalProfile);
        $service->professionalServices()->create(['professional_id' => $professional->id, 'is_active' => true, 'is_visible_public' => true]);
        $this->profiledCheckup('Percorso pubblico', 'percorso-pubblico', true, true, [$service]);

        $this->getJson('/api/v1/public/check-up/percorso-pubblico')->assertOk()
            ->assertJsonPath('data.checkup.name', 'Percorso pubblico')
            ->assertJsonPath('data.web_profile.public_slug', 'percorso-pubblico')
            ->assertJsonPath('data.included_services.0.href', '/prestazioni/ecg-pubblico')
            ->assertJsonPath('data.included_services.0.icon_url', 'http://127.0.0.1:8000/storage/areas/cardio.svg')
            ->assertJsonPath('data.areas.0.href', '/aree-mediche/cardiologia')
            ->assertJsonPath('data.professionals.0.href', '/equipe/ada-rossi')
            ->assertJsonMissing(['email' => 'privata@example.test'])
            ->assertJsonMissing(['notes' => 'Riservato']);

        $serviceProfile->update(['is_web_enabled' => false]);
        $areaProfile->update(['is_web_enabled' => false]);
        $professionalProfile->update(['is_web_enabled' => false]);
        $this->getJson('/api/v1/public/check-up/percorso-pubblico')->assertOk()
            ->assertJsonPath('data.included_services.0.href', null)
            ->assertJsonPath('data.areas.0.href', null)
            ->assertJsonCount(0, 'data.professionals');
    }

    #[Test]
    public function archive_preserves_everything_restore_stays_inactive_and_force_delete_is_safe(): void
    {
        $this->actingAsAdmin();
        $service = $this->service('Componente');
        $checkup = $this->profiledCheckup('Conservato', 'conservato', true, true, [$service]);
        $profileId = $checkup->webProfile->id;

        $this->deleteJson("/api/v1/checkups/{$checkup->id}")->assertNoContent();
        $this->assertSoftDeleted('checkups', ['id' => $checkup->id, 'is_active' => false]);
        $this->assertDatabaseHas('checkup_web_profiles', ['id' => $profileId, 'is_web_enabled' => true]);
        $this->assertDatabaseHas('checkup_services', ['checkup_id' => $checkup->id, 'service_id' => $service->id]);
        $this->postJson("/api/v1/checkups/{$checkup->id}/restore")->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/v1/public/check-up/conservato')->assertNotFound();
        $this->deleteJson("/api/v1/checkups/{$checkup->id}/force")->assertConflict()
            ->assertJsonPath('dependencies.services', 1)->assertJsonPath('dependencies.web_profile', 1);

        $unused = Checkup::factory()->create(['is_active' => false]);
        $this->deleteJson("/api/v1/checkups/{$unused->id}")->assertNoContent();
        $this->deleteJson("/api/v1/checkups/{$unused->id}/force")->assertNoContent();
        $this->assertDatabaseMissing('checkups', ['id' => $unused->id]);
    }

    #[Test]
    public function management_and_web_checkup_routes_require_manage_services(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Admin]);
        $editor->givePermissionTo(AdminPermission::VIEW_BACKOFFICE->value);
        Sanctum::actingAs($editor);
        $this->getJson('/api/v1/admin/check-up')->assertForbidden();
        $this->getJson('/api/v1/checkups')->assertForbidden();

        $this->actingAsAdmin();
        $this->getJson('/api/v1/admin/check-up')->assertOk();
        $this->getJson('/api/v1/checkups')->assertOk();
    }

    private function actingAsAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    private function service(string $name): Service
    {
        return Service::factory()->create([
            'category_id' => null,
            'display_name' => $name, 'canonical_name' => $name,
            'importo_prestazione' => 50, 'default_duration_minutes' => 20, 'is_active' => true,
        ]);
    }

    private function profiledCheckup(string $name, string $slug, bool $active, bool $enabled, array $services = []): Checkup
    {
        $checkup = Checkup::factory()->create(['display_name' => $name, 'is_active' => $active]);
        foreach ($services as $order => $service) {
            $checkup->items()->create(['service_id' => $service->id, 'sort_order' => $order]);
        }
        $profile = CheckupWebProfile::query()->create([
            'checkup_id' => $checkup->id, 'public_slug' => $slug,
            'is_web_enabled' => $enabled, 'list_sort_order' => 0,
        ]);
        app(CheckupWebContentService::class)->initializeSections($profile);

        return $checkup->refresh()->load('webProfile');
    }

    private function payload(string $slug): array
    {
        return [
            'public_slug' => $slug, 'short_description' => 'Descrizione pubblica.',
            'category_label' => 'Prevenzione', 'is_web_enabled' => true,
            'list_sort_order' => 3, 'is_local_seo_enabled' => true, 'robots' => 'index,follow',
            'sections' => collect(CheckupSectionDefinition::keys())->map(fn (string $key) => [
                'key' => $key, 'title' => $key === 'hero' ? null : CheckupSectionDefinition::DEFINITIONS[$key],
                'intro' => null, 'is_active' => true,
                'data' => match ($key) {
                    'what_is', 'preparation' => ['items' => []],
                    'target' => ['groups' => []], 'procedure' => ['steps' => []], default => [],
                },
            ])->all(),
            'faqs' => [],
        ];
    }
}
