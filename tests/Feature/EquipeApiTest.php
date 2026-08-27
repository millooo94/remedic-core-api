<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\Section;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use App\Models\User;
use App\Services\EquipeContentService;
use App\Services\MedicalAreaContentService;
use App\Services\ServiceWebContentService;
use App\Services\SiteIndexPageInitializer;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EquipeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function professional_is_the_master_for_identity_credentials_and_career(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->givePermissionTo(AdminPermission::MANAGE_DOCTORS->value);
        Sanctum::actingAs($user);
        $specialization = Specialization::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'is_web_active' => true]
        );
        $specialization->update(['is_active' => true, 'is_web_active' => true]);

        $response = $this->postJson('/api/v1/professionals', [
            'subject_type' => 'individual',
            'gender' => 'female',
            'honorific_prefix' => 'Dott.ssa',
            'first_name' => 'Giulia',
            'last_name' => 'Ferri',
            'birth_date' => '1982-04-15',
            'birth_place' => 'Catania',
            'specialization_ids' => [$specialization->id],
            'degrees' => [['title' => 'Laurea in Medicina', 'awarded_on' => '2007-07-20']],
            'academic_specializations' => [['title' => 'Specializzazione in Cardiologia', 'awarded_on' => '2012-10-10']],
            'board_registrations' => [['board_name' => 'Ordine dei Medici di Catania', 'registration_number' => 'CT-1234', 'registered_on' => '2008-01-11']],
            'career_experiences' => [[
                'year_from' => 2019, 'year_to' => null, 'is_current' => true, 'title' => 'Cardiologa',
                'organization' => 'Remedic', 'description' => 'Attività clinica.',
            ], [
                'year_from' => 2012, 'year_to' => 2018, 'is_current' => false, 'title' => 'Dirigente medico',
                'organization' => 'Ospedale Civico',
            ]],
        ])->assertCreated()
            ->assertJsonPath('honorific_prefix', 'Dott.ssa')
            ->assertJsonPath('birth_place', 'Catania')
            ->assertJsonPath('board_registrations.0.registration_number', 'CT-1234')
            ->assertJsonPath('career_experiences.0.is_current', true);

        $professionalId = (int) $response->json('id');
        $degreeId = (int) $response->json('degrees.0.id');
        $careerId = (int) $response->json('career_experiences.0.id');
        $olderCareerId = (int) $response->json('career_experiences.1.id');

        $this->putJson("/api/v1/professionals/{$professionalId}", [
            'subject_type' => 'individual', 'gender' => 'female', 'honorific_prefix' => 'Prof.ssa',
            'first_name' => 'Giulia', 'last_name' => 'Ferri', 'birth_date' => '1982-04-15', 'birth_place' => 'Catania',
            'specialization_ids' => [$specialization->id],
            'degrees' => [['id' => $degreeId, 'title' => 'Laurea magistrale in Medicina', 'awarded_on' => '2007-07-20']],
            'academic_specializations' => [],
            'board_registrations' => [],
            'career_experiences' => [
                ['id' => $olderCareerId, 'year_from' => 2012, 'year_to' => 2018, 'is_current' => false, 'title' => 'Dirigente medico'],
                ['id' => $careerId, 'year_from' => 2019, 'is_current' => true, 'title' => 'Responsabile Cardiologia'],
            ],
        ])->assertOk()
            ->assertJsonPath('degrees.0.id', $degreeId)
            ->assertJsonPath('career_experiences.0.id', $olderCareerId)
            ->assertJsonPath('career_experiences.0.sort_order', 0)
            ->assertJsonPath('career_experiences.1.id', $careerId)
            ->assertJsonPath('career_experiences.1.sort_order', 1);

        $this->assertDatabaseHas('professionals', ['id' => $professionalId, 'honorific_prefix' => 'Prof.ssa']);
        $this->assertDatabaseMissing('professional_academic_specializations', ['professional_id' => $professionalId]);
    }

    #[Test]
    public function equipe_is_a_single_immutable_editorial_extension_with_default_sections(): void
    {
        $this->actingAsWebAdmin();
        $professional = Professional::factory()->create(['honorific_prefix' => 'Dott.', 'avatar_path' => 'professionals/master.jpg']);

        $created = $this->postJson('/api/v1/admin/equipe', [
            'professional_id' => $professional->id,
            'slug' => 'mario-rossi',
            'is_web_enabled' => false,
        ])->assertCreated()
            ->assertJsonPath('professional.honorific_prefix', 'Dott.')
            ->assertJsonPath('professional.avatar_path', 'professionals/master.jpg')
            ->assertJsonCount(7, 'sections')
            ->assertJsonMissingPath('professional.email');

        $profileId = (int) $created->json('id');
        app(EquipeContentService::class)->initializeSections(ProfessionalPublicProfile::findOrFail($profileId));
        $this->assertSame(7, Section::query()
            ->where('sectionable_type', ProfessionalPublicProfile::class)
            ->where('sectionable_id', $profileId)
            ->count());
        $this->postJson('/api/v1/admin/equipe', ['professional_id' => $professional->id, 'slug' => 'duplicate'])
            ->assertUnprocessable()->assertJsonValidationErrors(['professional_id']);

        $other = Professional::factory()->create();
        $this->putJson("/api/v1/admin/equipe/{$profileId}", [
            'professional_id' => $other->id,
            'slug' => 'mario-rossi',
            'title_prefix' => 'Dr.',
            'full_name' => 'Nome alterato',
            'avatar_path' => 'override.jpg',
            'specialization_ids' => [999],
            'service_ids' => [999],
            'is_active' => false,
            'is_web_enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'professional_id', 'title_prefix', 'full_name', 'avatar_path', 'specialization_ids', 'service_ids', 'is_active',
        ]);

        $this->assertSame($professional->id, ProfessionalPublicProfile::findOrFail($profileId)->professional_id);
    }

    #[Test]
    public function typed_sections_reorder_publish_and_slug_redirect_without_sensitive_data_or_faqs(): void
    {
        $this->actingAsWebAdmin();
        $specialization = Specialization::query()->firstOrCreate(
            ['slug' => 'cardiologia'],
            ['name' => 'Cardiologia', 'is_active' => true, 'is_web_active' => true]
        );
        $specialization->update(['is_active' => true, 'is_web_active' => true]);
        $areaProfile = SpecializationWebProfile::query()->create([
            'specialization_id' => $specialization->id,
            'slug' => 'cardiologia-area',
            'is_web_enabled' => true,
        ]);
        app(MedicalAreaContentService::class)->initializeSections($areaProfile);
        $professional = Professional::factory()->create([
            'honorific_prefix' => 'Dott.ssa', 'email' => 'internal@example.com', 'iban' => 'IT60X0542811101000000123456',
            'notes' => 'Riservato', 'birth_date' => '1980-01-01', 'birth_place' => 'Roma', 'avatar_path' => 'professionals/master.jpg',
        ]);
        $professional->specializations()->attach($specialization->id, ['is_primary' => true, 'sort_order' => 0]);
        $professional->careerExperiences()->create(['year_from' => 2019, 'is_current' => true, 'title' => 'Cardiologa', 'organization' => 'Remedic']);

        $profileId = (int) $this->postJson('/api/v1/admin/equipe', [
            'professional_id' => $professional->id, 'slug' => 'giulia-ferri', 'is_web_enabled' => true,
            'short_bio' => 'Introduzione', 'bio_content' => "Primo paragrafo\n\nSecondo paragrafo",
        ])->assertCreated()->json('id');

        $typed = $this->putJson("/api/v1/admin/equipe/{$profileId}", [
            'slug' => 'giulia-ferri', 'is_web_enabled' => true,
            'approach_content' => 'Ascolto e decisioni condivise.',
            'competencies' => [
                ['title' => 'Cardiologia preventiva', 'description' => 'Prevenzione.', 'icon_key' => 'success', 'is_active' => true],
                ['title' => 'Competenza nascosta', 'is_active' => false],
            ],
            'approach_principles' => [['label' => 'Ascolto', 'icon_key' => 'user', 'is_active' => true]],
            'scientific_activities' => [['contribution_type' => 'scientific_article', 'year' => 2025, 'title' => 'Studio clinico', 'source' => 'Rivista', 'url' => 'https://example.com/studio', 'is_active' => true]],
        ])->assertOk();
        $competencyId = (int) $typed->json('competencies.0.id');

        $this->putJson("/api/v1/admin/equipe/{$profileId}", [
            'slug' => 'giulia-ferri-nuovo', 'is_web_enabled' => true, 'hero_competency_ids' => [$competencyId],
        ])->assertOk()->assertJsonPath('hero_competency_ids.0', $competencyId);

        $sections = collect($typed->json('sections'))->reverse()->values()->map(fn ($section) => [
            'key' => $section['key'], 'is_active' => $section['key'] !== 'biography',
        ])->all();
        $this->patchJson("/api/v1/admin/equipe/{$profileId}/sections", ['sections' => $sections])
            ->assertOk()->assertJsonPath('sections.0.key', 'services');

        $public = $this->getJson('/api/v1/public/equipe/giulia-ferri-nuovo')->assertOk()
            ->assertJsonPath('data.title', 'Dott.ssa')
            ->assertJsonPath('data.specialization', 'Cardiologia')
            ->assertJsonPath('data.image_url', fn (string $url) => str_contains($url, '/storage/professionals/master.jpg'))
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.iban')
            ->assertJsonMissingPath('data.birth_date')
            ->assertJsonMissingPath('data.faq_items');
        $this->getJson('/api/v1/public/professionals/giulia-ferri-nuovo')
            ->assertOk()
            ->assertJsonPath('data.slug', 'giulia-ferri-nuovo');

        ProfessionalPublicProfile::query()->whereKey($profileId)->update(['is_active' => false]);
        $this->getJson('/api/v1/public/equipe/giulia-ferri-nuovo')->assertOk();
        ProfessionalPublicProfile::query()->whereKey($profileId)->update([
            'is_active' => true,
            'is_web_enabled' => false,
        ]);
        $this->getJson('/api/v1/public/equipe/giulia-ferri-nuovo')->assertNotFound();
        ProfessionalPublicProfile::query()->whereKey($profileId)->update(['is_web_enabled' => true]);

        $keys = collect($public->json('data.sections'))->pluck('key');
        $this->assertFalse($keys->contains('biography'));
        $this->assertFalse(collect($public->json('data.sections'))->flatten()->containsStrict('Competenza nascosta'));
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/equipe/giulia-ferri', 'to_path' => '/equipe/giulia-ferri-nuovo',
            'source_type' => Redirect::SOURCE_TYPE_EQUIPE_PROFILE,
        ]);

        $this->putJson("/api/v1/admin/equipe/{$profileId}", [
            'slug' => 'giulia-ferri-nuovo', 'is_web_enabled' => true, 'bio_content' => null,
        ])->assertOk();
        $reactivatedSections = collect($sections)->map(fn ($section) => [
            'key' => $section['key'], 'is_active' => true,
        ])->all();
        $this->patchJson("/api/v1/admin/equipe/{$profileId}/sections", ['sections' => $reactivatedSections])->assertOk();
        $emptyBiographyKeys = collect(
            $this->getJson('/api/v1/public/equipe/giulia-ferri-nuovo')->assertOk()->json('data.sections')
        )->pluck('key');
        $this->assertFalse($emptyBiographyKeys->contains('biography'));
    }

    #[Test]
    public function professional_detail_exposes_all_public_specializations(): void
    {
        $professional = Professional::factory()->create(['is_active' => true]);
        $profile = ProfessionalPublicProfile::query()->create([
            'professional_id' => $professional->id,
            'slug' => 'dottore-multi',
            'is_active' => true,
            'is_web_enabled' => true,
        ]);
        app(EquipeContentService::class)->initializeSections($profile);

        $publish = function (ProfessionalPublicProfile|SpecializationWebProfile $owner, string $locale, string $title, string $slug, string $state = 'published', bool $reviewed = true): void {
            $italian = $owner->translations()->where('locale', 'it')->firstOrFail();
            $owner->translations()->create([
                'locale' => $locale,
                'title' => $title,
                'slug' => $slug,
                'publication_state' => $state,
                'source_revision' => $italian->source_revision,
                'reviewed_source_revision' => $reviewed ? $italian->source_revision : 'outdated-source-revision',
            ]);
        };
        $area = function (string $name, string $slug, bool $active = true, bool $webEnabled = true): array {
            $specialization = Specialization::query()->create([
                'name' => $name,
                'slug' => 'master-'.$slug,
                'is_active' => $active,
                'is_web_active' => $active,
            ]);
            $webProfile = SpecializationWebProfile::query()->create([
                'specialization_id' => $specialization->id,
                'slug' => $slug,
                'is_web_enabled' => $webEnabled,
            ]);

            return [$specialization, $webProfile];
        };

        [$cardiology, $cardiologyProfile] = $area('Cardiologia', 'cardiologia');
        [$neurology, $neurologyProfile] = $area('Neurologia', 'neurologia');
        [$dermatology, $dermatologyProfile] = $area('Dermatologia', 'dermatologia');
        [, $inactiveProfile] = $area('Area inattiva', 'area-inattiva', false);
        [, $webDisabledProfile] = $area('Area web disabilitata', 'area-web-disabilitata', true, false);
        [, $draftProfile] = $area('Area draft', 'area-draft');
        [, $staleProfile] = $area('Area stale', 'area-stale');
        [, $missingLocaleProfile] = $area('Area senza locale', 'area-senza-locale');

        foreach ([
            ['en', 'Doctor multi EN', 'doctor-multi-en'],
            ['es', 'Doctor multi ES', 'doctor-multi-es'],
            ['fr', 'Doctor multi FR', 'doctor-multi-fr'],
        ] as [$locale, $title, $slug]) {
            $publish($profile, $locale, $title, $slug);
        }
        $publish($cardiologyProfile, 'en', 'Cardiology', 'cardiology-en');
        $publish($cardiologyProfile, 'es', 'Cardiología', 'cardiologia-es');
        $publish($neurologyProfile, 'en', 'Neurology', 'neurology-en');
        $publish($neurologyProfile, 'fr', 'Neurologie', 'neurologie-fr');
        $publish($draftProfile, 'en', 'Draft area', 'draft-area', 'draft');
        $publish($staleProfile, 'en', 'Stale area', 'stale-area', 'published', false);
        $publish($inactiveProfile, 'en', 'Inactive area', 'inactive-area');
        $publish($webDisabledProfile, 'en', 'Disabled area', 'disabled-area');

        $professional->specializations()->attach($cardiology->id, ['is_primary' => true, 'sort_order' => 2]);
        $professional->specializations()->attach($neurology->id, ['is_primary' => false, 'sort_order' => 0]);
        $professional->specializations()->attach($dermatology->id, ['is_primary' => false, 'sort_order' => 1]);
        foreach ([$inactiveProfile, $webDisabledProfile, $draftProfile, $staleProfile, $missingLocaleProfile] as $index => $profileToExclude) {
            $professional->specializations()->attach($profileToExclude->specialization_id, ['is_primary' => false, 'sort_order' => $index + 3]);
        }

        $english = $this->getJson('/api/v1/public/equipe/doctor-multi-en?locale=en')->assertOk();
        $this->assertSame(['Cardiology', 'Neurology'], array_column($english->json('data.areas'), 'name'));
        $this->assertSame(['/en/medical-areas/cardiology-en', '/en/medical-areas/neurology-en'], array_column($english->json('data.areas'), 'href'));

        $spanish = $this->getJson('/api/v1/public/equipe/doctor-multi-es?locale=es')->assertOk();
        $this->assertSame(['Cardiología'], array_column($spanish->json('data.areas'), 'name'));
        $this->assertSame('/es/areas-medicas/cardiologia-es', $spanish->json('data.areas.0.href'));

        $french = $this->getJson('/api/v1/public/equipe/doctor-multi-fr?locale=fr')->assertOk();
        $this->assertSame(['Neurologie'], array_column($french->json('data.areas'), 'name'));
        $this->assertSame('/fr/specialites-medicales/neurologie-fr', $french->json('data.areas.0.href'));

        SpecializationWebProfile::query()->whereIn('id', [$draftProfile->id, $staleProfile->id, $missingLocaleProfile->id])->update(['is_web_enabled' => false]);
        $italian = $this->getJson('/api/v1/public/equipe/dottore-multi')->assertOk()
            ->assertJsonPath('data.specialization', 'Cardiologia')
            ->assertJsonMissingPath('data.areas.0.id')
            ->assertJsonMissingPath('data.areas.0.pivot')
            ->assertJsonMissingPath('data.areas.0.sort_order')
            ->assertJsonMissingPath('data.areas.0.professional_id')
            ->assertJsonMissingPath('data.areas.0.specialization_id');
        $this->assertSame(['Cardiologia', 'Neurologia', 'Dermatologia'], array_column($italian->json('data.areas'), 'name'));
        $this->assertSame(['/aree-mediche/cardiologia', '/aree-mediche/neurologia', '/aree-mediche/dermatologia'], array_column($italian->json('data.areas'), 'href'));
        $this->assertSame(['name', 'slug', 'href', 'icon_url', 'is_primary'], array_keys($italian->json('data.areas.0')));
        $this->assertTrue($italian->json('data.areas.0.is_primary'));
        $this->assertFalse($italian->json('data.areas.1.is_primary'));
        $this->assertSame('Cardiologia', collect($italian->json('data.sections'))->firstWhere('key', 'hero')['data']['primary_specialization']['name']);

        app(SiteIndexPageInitializer::class)->initialize();
        SiteIndexPage::query()->where('internal_key', 'equipe_index')->update(['is_active' => true, 'published_at' => now()->subMinute()]);
        $index = $this->getJson('/api/v1/public/site-indexes/equipe_index')->assertOk();
        $this->assertSame('Cardiologia', $index->json('data.items.0.primary_area.name'));
        $this->assertSame(['Cardiologia', 'Neurologia'], $index->json('data.items.0.tags'));
    }

    #[Test]
    public function public_equipe_has_no_master_fallback_and_respects_effective_visibility(): void
    {
        $professional = Professional::factory()->create(['is_active' => true]);
        $this->getJson('/api/v1/public/equipe')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/professionals')->assertOk()->assertJsonCount(0, 'data');

        $profile = ProfessionalPublicProfile::query()->create([
            'professional_id' => $professional->id, 'slug' => 'visible-doctor', 'is_active' => true, 'is_web_enabled' => true,
        ]);
        app(EquipeContentService::class)->initializeSections($profile);
        $this->getJson('/api/v1/public/equipe')->assertOk()->assertJsonCount(1, 'data');

        $professional->update(['is_active' => false]);
        $this->getJson('/api/v1/public/equipe')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('professional_public_profiles', ['id' => $profile->id, 'is_web_enabled' => true]);
    }

    #[Test]
    public function equipe_services_are_derived_read_only_editable_as_section_metadata_and_omitted_when_empty(): void
    {
        $this->actingAsWebAdmin();
        $professional = Professional::factory()->create(['is_active' => true]);
        $profileId = (int) $this->postJson('/api/v1/admin/equipe', [
            'professional_id' => $professional->id,
            'slug' => 'medico-prestazioni',
            'is_web_enabled' => true,
        ])->assertCreated()
            ->assertJsonFragment(['key' => 'services', 'label' => 'Prestazioni'])
            ->assertJsonMissingPath('faqs')
            ->json('id');

        $this->getJson('/api/v1/public/equipe/medico-prestazioni')->assertOk()
            ->assertJsonMissing(['key' => 'services']);

        $service = Service::factory()->create([
            'category_id' => ServiceCategory::query()->value('id'),
            'display_name' => 'Visita specialistica',
            'is_active' => true,
            'is_web_active' => true,
        ]);
        $serviceProfile = ServiceWebProfile::query()->create([
            'service_id' => $service->id,
            'public_slug' => 'visita-specialistica',
            'is_web_enabled' => true,
        ]);
        app(ServiceWebContentService::class)->initializeSections($serviceProfile);
        $professional->professionalServices()->create([
            'service_id' => $service->id,
            'is_active' => true,
            'is_visible_public' => true,
            'public_sort_order' => 0,
        ]);

        $profile = $this->getJson("/api/v1/admin/equipe/{$profileId}")->assertOk()->json();
        $sections = collect($profile['sections'])->map(fn ($section) => [
            'key' => $section['key'],
            'is_active' => $section['is_active'],
            'title' => $section['key'] === 'services' ? 'Le mie prestazioni' : null,
            'intro' => $section['key'] === 'services' ? 'Prestazioni disponibili.' : null,
        ])->all();
        $this->patchJson("/api/v1/admin/equipe/{$profileId}/sections", [
            'sections' => $sections,
            'service_ids' => [$service->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['service_ids']);
        $this->patchJson("/api/v1/admin/equipe/{$profileId}/sections", ['sections' => $sections])
            ->assertOk()->assertJsonFragment(['key' => 'services', 'title' => 'Le mie prestazioni', 'intro' => 'Prestazioni disponibili.']);

        $public = $this->getJson('/api/v1/public/equipe/medico-prestazioni')->assertOk()
            ->assertJsonFragment(['key' => 'services'])
            ->assertJsonFragment(['name' => 'Visita specialistica'])
            ->assertJsonFragment(['title' => 'Le mie prestazioni']);
        $this->assertFalse(collect($public->json('data.sections'))->pluck('key')->contains('faqs'));

        $service->update(['is_active' => false]);
        $this->getJson('/api/v1/public/equipe/medico-prestazioni')->assertOk()
            ->assertJsonMissing(['key' => 'services']);
    }

    #[Test]
    public function hidden_professionals_are_not_exposed_through_specializations_or_services(): void
    {
        $specialization = Specialization::query()->firstOrCreate(
            ['slug' => 'neurologia'],
            ['name' => 'Neurologia', 'is_active' => true, 'is_web_active' => true]
        );
        $specialization->update(['is_active' => true, 'is_web_active' => true]);
        $areaProfile = SpecializationWebProfile::query()->create([
            'specialization_id' => $specialization->id,
            'slug' => 'neurologia',
            'is_web_enabled' => true,
        ]);
        app(MedicalAreaContentService::class)->initializeSections($areaProfile);
        $service = Service::factory()->create([
            'category_id' => ServiceCategory::query()->value('id'),
            'is_active' => true,
            'is_web_active' => true,
        ]);
        $serviceProfile = ServiceWebProfile::query()->create([
            'service_id' => $service->id,
            'public_slug' => 'prestazione-neurologica',
            'is_web_enabled' => true,
        ]);
        app(ServiceWebContentService::class)->initializeSections($serviceProfile);
        $service->specializations()->attach($specialization->id, ['sort_order' => 0, 'is_primary' => true]);
        $professional = Professional::factory()->create(['is_active' => true]);
        $professional->specializations()->attach($specialization->id, ['sort_order' => 0, 'is_primary' => true]);
        $professional->professionalServices()->create([
            'service_id' => $service->id, 'is_active' => true, 'is_visible_public' => true,
        ]);

        $this->getJson("/api/v1/public/specializations/{$specialization->slug}")
            ->assertOk()->assertJsonCount(0, 'data.doctors');
        $this->getJson("/api/v1/public/services/{$service->slug}")
            ->assertOk()->assertJsonCount(0, 'data.doctors');
    }

    #[Test]
    public function section_keys_are_unique_and_profile_delete_cleans_editorial_relations(): void
    {
        $this->actingAsWebAdmin();
        $professional = Professional::factory()->create();
        $created = $this->postJson('/api/v1/admin/equipe', [
            'professional_id' => $professional->id,
            'slug' => 'profilo-da-rimuovere',
            'competencies' => [['title' => 'Competenza']],
            'approach_principles' => [['label' => 'Principio']],
            'scientific_activities' => [['contribution_type' => 'other', 'title' => 'Contributo']],
        ])->assertCreated();
        $profileId = (int) $created->json('id');
        ProfessionalPublicProfile::findOrFail($profileId)->faqs()->create([
            'question' => 'FAQ legacy', 'answer' => 'Da rimuovere', 'sort_order' => 0,
        ]);

        try {
            Section::query()->create([
                'sectionable_type' => ProfessionalPublicProfile::class,
                'sectionable_id' => $profileId,
                'key' => 'hero',
                'sort_order' => 99,
            ]);
            $this->fail('Il database ha accettato una chiave sezione duplicata.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->deleteJson("/api/v1/admin/equipe/{$profileId}")->assertNoContent();
        $this->assertDatabaseHas('professionals', ['id' => $professional->id]);
        $this->assertDatabaseMissing('sections', ['sectionable_type' => ProfessionalPublicProfile::class, 'sectionable_id' => $profileId]);
        $this->assertDatabaseMissing('faq_items', ['faqable_type' => ProfessionalPublicProfile::class, 'faqable_id' => $profileId]);
        $this->assertDatabaseMissing('professional_profile_competencies', ['professional_public_profile_id' => $profileId]);
        $this->assertDatabaseMissing('professional_profile_approach_principles', ['professional_public_profile_id' => $profileId]);
        $this->assertDatabaseMissing('professional_profile_scientific_activities', ['professional_public_profile_id' => $profileId]);
    }

    #[Test]
    public function deleting_an_unreferenced_professional_explicitly_cleans_profile_morph_children(): void
    {
        $this->actingAsWebAdmin();
        $professional = Professional::factory()->create();
        $profile = ProfessionalPublicProfile::query()->create([
            'professional_id' => $professional->id,
            'slug' => 'profilo-con-owner-rimosso',
            'is_web_enabled' => true,
        ]);
        app(EquipeContentService::class)->initializeSections($profile);
        $profile->faqs()->create([
            'question' => 'Domanda?',
            'answer' => 'Risposta.',
            'sort_order' => 0,
        ]);

        $this->deleteJson("/api/v1/professionals/{$professional->id}")->assertNoContent();
        $this->assertDatabaseMissing('professionals', ['id' => $professional->id]);
        $this->assertDatabaseMissing('professional_public_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('sections', [
            'sectionable_type' => ProfessionalPublicProfile::class,
            'sectionable_id' => $profile->id,
        ]);
        $this->assertDatabaseMissing('faq_items', [
            'faqable_type' => ProfessionalPublicProfile::class,
            'faqable_id' => $profile->id,
        ]);
    }

    private function actingAsWebAdmin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
