<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\ConsentPolicyVersion;
use App\Models\ConsentPreferenceChange;
use App\Models\ConsentRecord;
use App\Models\Page;
use App\Models\Professional;
use App\Models\Redirect;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Specialization;
use App\Models\User;
use App\Support\MedicalAreas\MedicalAreaSectionDefinition;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminBackofficeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.primary_admin.email', 'humancaretelemedicine@gmail.com');

        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function auth_me_exposes_backoffice_access_metadata(): void
    {
        $user = User::factory()->create([
            'email' => 'humancaretelemedicine@gmail.com',
        ]);

        $user->assignRole(Role::findByName(AdminRole::SUPER_ADMIN->value, 'web'));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('can_access_backoffice', true)
            ->assertJsonPath('backoffice_roles.0', AdminRole::SUPER_ADMIN->value);
    }

    #[Test]
    public function super_admin_can_list_backoffice_users(): void
    {
        $user = User::factory()->create([
            'email' => 'humancaretelemedicine@gmail.com',
        ]);
        $user->assignRole(Role::findByName(AdminRole::SUPER_ADMIN->value, 'web'));

        User::factory()->count(2)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    #[Test]
    public function editor_cannot_list_backoffice_users(): void
    {
        $user = User::factory()->create([
            'email' => 'editor@example.com',
        ]);
        $user->assignRole(Role::findByName(AdminRole::EDITOR->value, 'web'));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_crud_pages_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'pages-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        Sanctum::actingAs($user);

        $slug = 'chi-siamo-admin-test-'.str()->lower(str()->random(8));

        $created = $this->postJson('/api/v1/admin/pages', [
            'title' => 'Chi siamo FAQ test',
            'slug' => $slug,
            'template' => 'default',
            'excerpt' => 'Pagina istituzionale',
            'intro_text' => '<p>Intro</p>',
            'is_active' => true,
            'sections' => [
                [
                    'key' => 'hero',
                    'title' => 'Hero',
                    'subtitle' => 'Sub',
                    'content' => '<p>Corpo</p>',
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            ],
            'faqs' => [
                [
                    'question' => 'Domanda 1',
                    'answer' => 'Risposta 1',
                    'sort_order' => 0,
                    'is_active' => true,
                    'is_structured_data' => true,
                ],
            ],
        ])->assertCreated();

        $pageId = (int) $created->json('id');

        $this->assertDatabaseHas('pages', [
            'id' => $pageId,
            'slug' => $slug,
        ]);

        $this->putJson("/api/v1/admin/pages/{$pageId}", [
            'title' => 'Chi siamo oggi',
            'slug' => $slug,
            'template' => 'default',
            'excerpt' => 'Pagina aggiornata',
            'intro_text' => '<p>Intro aggiornata</p>',
            'is_active' => false,
            'sections' => [],
            'faqs' => [],
        ])->assertOk()
            ->assertJsonPath('title', 'Chi siamo oggi')
            ->assertJsonPath('is_active', false);

        $this->deleteJson("/api/v1/admin/pages/{$pageId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('pages', [
            'id' => $pageId,
        ]);
    }

    #[Test]
    public function page_faq_flags_are_persisted_and_reloaded_consistently(): void
    {
        $user = User::factory()->create([
            'email' => 'faq-pages-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        Sanctum::actingAs($user);
        $slug = 'chi-siamo-faq-test-'.str()->lower(str()->random(8));

        $created = $this->postJson('/api/v1/admin/pages', [
            'title' => 'Chi siamo FAQ test',
            'slug' => $slug,
            'template' => 'default',
            'excerpt' => 'Pagina istituzionale',
            'intro_text' => 'Pagina istituzionale',
            'faq_enabled' => true,
            'is_active' => true,
            'faqs' => [
                [
                    'question' => 'FAQ attiva',
                    'answer' => 'Risposta attiva',
                    'sort_order' => 0,
                    'is_active' => true,
                    'is_structured_data' => true,
                ],
                [
                    'question' => 'FAQ inattiva',
                    'answer' => 'Risposta inattiva',
                    'sort_order' => 1,
                    'is_active' => false,
                    'is_structured_data' => false,
                ],
            ],
        ])->assertCreated();

        $pageId = (int) $created->json('id');
        $firstFaqId = (int) $created->json('faqs.0.id');
        $secondFaqId = (int) $created->json('faqs.1.id');

        $this->assertDatabaseHas('faq_items', [
            'faqable_type' => Page::class,
            'faqable_id' => $pageId,
            'question' => 'FAQ inattiva',
            'is_active' => false,
            'is_structured_data' => false,
        ]);

        $this->putJson("/api/v1/admin/pages/{$pageId}", [
            'title' => 'Chi siamo FAQ test',
            'slug' => $slug,
            'template' => 'default',
            'excerpt' => 'Pagina istituzionale aggiornata',
            'intro_text' => 'Pagina istituzionale aggiornata',
            'faq_enabled' => true,
            'is_active' => true,
            'faqs' => [
                [
                    'id' => $firstFaqId,
                    'question' => 'FAQ attiva',
                    'answer' => 'Risposta attiva',
                    'sort_order' => 0,
                    'is_active' => false,
                    'is_structured_data' => true,
                ],
                [
                    'id' => $secondFaqId,
                    'question' => 'FAQ visibile senza schema',
                    'answer' => 'Risposta visibile senza schema',
                    'sort_order' => 1,
                    'is_active' => true,
                    'is_structured_data' => false,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('faq_enabled', true)
            ->assertJsonPath('faqs.0.question', 'FAQ attiva')
            ->assertJsonPath('faqs.0.is_active', false)
            ->assertJsonPath('faqs.0.is_structured_data', true)
            ->assertJsonPath('faqs.1.question', 'FAQ visibile senza schema')
            ->assertJsonPath('faqs.1.is_active', true)
            ->assertJsonPath('faqs.1.is_structured_data', false);

        $this->getJson("/api/v1/admin/pages/{$pageId}")
            ->assertOk()
            ->assertJsonPath('faq_enabled', true)
            ->assertJsonPath('faqs.0.question', 'FAQ attiva')
            ->assertJsonPath('faqs.0.is_active', false)
            ->assertJsonPath('faqs.0.is_structured_data', true)
            ->assertJsonPath('faqs.1.question', 'FAQ visibile senza schema')
            ->assertJsonPath('faqs.1.is_active', true)
            ->assertJsonPath('faqs.1.is_structured_data', false);
    }

    #[Test]
    public function admin_can_read_and_update_site_settings_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'settings-admin@example.com',
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        $settings = SiteSetting::ensureSingleton();
        $settings->update([
            'clinic_name' => 'Remedic',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/site-settings')
            ->assertOk()
            ->assertJsonPath('center.clinic_name', 'Remedic');

        $this->putJson('/api/v1/admin/site-settings', [
            'site_url' => 'https://remedic.it',
            'default_meta_title' => 'Remedic Web',
            'clinic_name' => 'Tentativo non autorizzato',
        ])->assertOk()
            ->assertJsonPath('default_meta_title', 'Remedic Web')
            ->assertJsonPath('center.clinic_name', 'Remedic');

        $this->assertDatabaseHas('site_settings', [
            'id' => $settings->id,
            'clinic_name' => 'Remedic',
            'default_meta_title' => 'Remedic Web',
        ]);
    }

    #[Test]
    public function admin_can_crud_blog_posts_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'blog-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/admin/blog-posts', [
            'title' => 'Nuovo articolo',
            'slug' => 'nuovo-articolo',
            'excerpt' => 'Estratto',
            'cover_image' => '/uploads/blog/cover.jpg',
            'is_active' => true,
            'published_at' => now()->toIso8601String(),
            'sections' => [
                [
                    'key' => 'hero',
                    'title' => 'Hero',
                    'content' => '<p>Corpo</p>',
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            ],
            'faqs' => [
                [
                    'question' => 'Domanda 1',
                    'answer' => 'Risposta 1',
                    'sort_order' => 0,
                    'is_active' => true,
                    'is_structured_data' => true,
                ],
            ],
        ])->assertCreated();

        $postId = (int) $created->json('id');
        $sectionId = (int) $created->json('sections.0.id');
        $faqId = (int) $created->json('faqs.0.id');

        $this->assertDatabaseHas('blog_posts', [
            'id' => $postId,
            'slug' => 'nuovo-articolo',
        ]);

        $this->putJson("/api/v1/admin/blog-posts/{$postId}", [
            'title' => 'Articolo aggiornato',
            'slug' => 'nuovo-articolo',
            'excerpt' => 'Estratto aggiornato',
            'cover_image' => '/uploads/blog/cover-2.jpg',
            'is_active' => false,
            'sections' => [],
            'faqs' => [],
        ])->assertOk()
            ->assertJsonPath('title', 'Articolo aggiornato')
            ->assertJsonPath('is_active', false);

        $this->deleteJson("/api/v1/admin/blog-posts/{$postId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('blog_posts', [
            'id' => $postId,
        ]);
        $this->assertDatabaseMissing('sections', ['id' => $sectionId]);
        $this->assertDatabaseMissing('faq_items', ['id' => $faqId]);
    }

    #[Test]
    public function editorial_sections_keep_identity_and_clear_image_when_switched_to_text(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/admin/blog-posts', [
            'title' => 'Sezioni editoriali',
            'slug' => 'sezioni-editoriali',
            'content_type' => 'news',
            'is_active' => true,
            'published_at' => now()->subMinute()->toIso8601String(),
            'sections' => [[
                'template' => 'image_text',
                'title' => 'Titolo sezione',
                'content' => '<p>Paragrafo</p>',
                'image_path' => 'blog-posts/drafts/section.webp',
                'is_active' => true,
            ]],
        ])->assertCreated()
            ->assertJsonPath('sections.0.template', 'image_text');

        $postId = (int) $created->json('id');
        $sectionId = (int) $created->json('sections.0.id');

        $this->putJson("/api/v1/admin/blog-posts/{$postId}", [
            'title' => 'Sezioni editoriali',
            'slug' => 'sezioni-editoriali',
            'content_type' => 'news',
            'is_active' => true,
            'published_at' => now()->subMinute()->toIso8601String(),
            'sections' => [[
                'id' => $sectionId,
                'template' => 'text',
                'title' => 'Titolo aggiornato',
                'content' => '<p>Paragrafo aggiornato</p>',
                'is_active' => true,
            ]],
        ])->assertOk()
            ->assertJsonPath('sections.0.id', $sectionId)
            ->assertJsonPath('sections.0.template', 'text')
            ->assertJsonPath('sections.0.image_path', null);

        $this->assertDatabaseHas('sections', ['id' => $sectionId, 'template' => 'text']);

        $this->getJson('/api/v1/public/news/sezioni-editoriali')
            ->assertOk()
            ->assertJsonPath('data.locale', 'it')
            ->assertJsonPath('data.available_locales', ['it'])
            ->assertJsonPath('data.author', null)
            ->assertJsonPath('data.sections.0.template', 'text')
            ->assertJsonPath('data.sections.0.body', '<p>Paragrafo aggiornato</p>')
            ->assertJsonPath('data.sections.0.image_url', null)
            ->assertJsonMissingPath('data.sections.0.key')
            ->assertJsonMissingPath('data.sections.0.sort_order');
    }

    #[Test]
    public function seo_manager_can_crud_redirects_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'redirect-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::SEO_MANAGER->value, 'web'));
        $user->givePermissionTo(AdminPermission::MANAGE_REDIRECTS->value);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/admin/redirects', [
            'from_path' => '/vecchia-pagina',
            'to_path' => '/nuova-pagina',
            'http_code' => 301,
            'is_active' => true,
        ])->assertCreated();

        $redirectId = (int) $created->json('id');

        $this->assertDatabaseHas('redirects', [
            'id' => $redirectId,
            'from_path' => '/vecchia-pagina',
            'to_path' => '/nuova-pagina',
        ]);

        $this->putJson("/api/v1/admin/redirects/{$redirectId}", [
            'from_path' => '/vecchia-pagina',
            'to_path' => 'https://example.com/destinazione',
            'http_code' => 302,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('http_code', 302)
            ->assertJsonPath('is_active', false);

        $this->deleteJson("/api/v1/admin/redirects/{$redirectId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('redirects', [
            'id' => $redirectId,
        ]);
    }

    #[Test]
    public function changing_a_page_slug_creates_and_updates_automatic_redirects(): void
    {
        $user = User::factory()->create([
            'email' => 'page-redirect-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/admin/pages', [
            'title' => 'Pagina redirect',
            'slug' => 'pagina-redirect',
            'template' => 'default',
            'is_active' => true,
            'sections' => [],
            'faqs' => [],
        ])->assertCreated();

        $pageId = (int) $created->json('id');

        $this->putJson("/api/v1/admin/pages/{$pageId}", [
            'title' => 'Pagina redirect',
            'slug' => 'pagina-redirect-nuova',
            'template' => 'default',
            'is_active' => true,
            'sections' => [],
            'faqs' => [],
        ])->assertOk();

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/pagina-redirect',
            'to_path' => '/pagina-redirect-nuova',
            'is_automatic' => true,
            'source_type' => Redirect::SOURCE_TYPE_PAGE,
            'source_id' => $pageId,
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/admin/pages/{$pageId}", [
            'title' => 'Pagina redirect',
            'slug' => 'pagina-redirect-finale',
            'template' => 'default',
            'is_active' => true,
            'sections' => [],
            'faqs' => [],
        ])->assertOk();

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/pagina-redirect',
            'to_path' => '/pagina-redirect-finale',
            'is_automatic' => true,
            'source_type' => Redirect::SOURCE_TYPE_PAGE,
            'source_id' => $pageId,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/pagina-redirect-nuova',
            'to_path' => '/pagina-redirect-finale',
            'is_automatic' => true,
            'source_type' => Redirect::SOURCE_TYPE_PAGE,
            'source_id' => $pageId,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function public_redirect_resolver_returns_the_active_rule(): void
    {
        Redirect::query()->create([
            'from_path' => '/vecchio-percorso',
            'to_path' => '/nuovo-percorso',
            'http_code' => 301,
            'is_active' => true,
            'is_automatic' => false,
        ]);

        $this->getJson('/api/v1/public/redirects/resolve?path=%2Fvecchio-percorso')
            ->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.destination', '/nuovo-percorso')
            ->assertJsonPath('data.status_code', 301)
            ->assertJsonMissingPath('data.source_id');
    }

    #[Test]
    public function admin_can_manage_only_web_extension_of_specializations_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'specialization-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        Sanctum::actingAs($user);

        $specializationId = (int) Specialization::query()->create([
            'name' => 'Cardiologia master test',
            'slug' => 'cardiologia-master-test',
            'robots' => 'index,follow',
            'is_local_seo_enabled' => true,
            'is_active' => true,
            'sort_order' => 3,
        ])->id;

        $this->postJson('/api/v1/admin/specializations', [
            'name' => 'Cardiologia',
        ])->assertMethodNotAllowed();

        $this->putJson("/api/v1/admin/specializations/{$specializationId}", [
            'slug' => 'cardiologia-pubblica-test',
            'short_description' => 'Descrizione aggiornata',
            'is_local_seo_enabled' => false,
            'is_web_enabled' => false,
            'sections' => collect(MedicalAreaSectionDefinition::DEFINITIONS)
                ->map(fn (string $label, string $key) => [
                    'key' => $key, 'title' => $label, 'intro' => null, 'is_active' => true, 'data' => [],
                ])->values()->all(),
            'faqs' => [],
        ])->assertOk()
            ->assertJsonPath('master.name', 'Cardiologia master test')
            ->assertJsonPath('web_profile.is_local_seo_enabled', false)
            ->assertJsonPath('master.is_active', true)
            ->assertJsonPath('web_profile.is_web_enabled', false);

        $this->deleteJson("/api/v1/admin/specializations/{$specializationId}")
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('specializations', [
            'id' => $specializationId,
            'name' => 'Cardiologia master test',
            'is_active' => true,
            'sort_order' => 3,
        ]);
    }

    #[Test]
    public function admin_can_manage_only_web_extension_of_services_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'service-web-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        Sanctum::actingAs($user);

        $serviceId = (int) Service::query()->create([
            'canonical_name' => 'Ecografia addome',
            'display_name' => 'Ecografia Addome Completo',
            'slug' => 'ecografia-addome-completo',
            'is_active' => true,
        ])->id;

        $this->postJson('/api/v1/admin/services', [
            'canonical_name' => 'Ecografia addome',
            'display_name' => 'Ecografia Addome Completo',
            'slug' => 'ecografia-addome-completo',
        ])->assertMethodNotAllowed();

        $this->putJson("/api/v1/admin/services/{$serviceId}", [
            'canonical_name' => 'Ecografia addome aggiornata',
            'display_name' => 'Ecografia Addome',
            'importo_prestazione' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['canonical_name', 'display_name', 'importo_prestazione']);

        $sections = collect([
            'hero', 'what_is', 'when_to_request', 'procedure',
            'preparation', 'price', 'faqs', 'equipe',
        ])->map(fn (string $key, int $index): array => [
            'key' => $key,
            'title' => $key === 'hero' ? null : ucfirst(str_replace('_', ' ', $key)),
            'intro' => $key === 'what_is' ? 'Descrizione aggiornata' : null,
            'is_active' => true,
            'data' => match ($key) {
                'what_is' => ['items' => [], 'bottom_note' => null],
                'when_to_request' => ['groups' => []],
                'procedure' => ['steps' => [], 'additional_info_enabled' => false, 'additional_info_title' => null, 'additional_info_text' => null, 'additional_info_items' => []],
                'preparation' => ['items' => [], 'info_box_enabled' => false, 'info_box_text' => null],
                default => [],
            },
        ])->all();

        $this->putJson("/api/v1/admin/services/{$serviceId}", [
            'public_slug' => 'ecografia-addome-completo-pubblica',
            'short_description' => 'Breve aggiornata',
            'is_web_enabled' => false,
            'is_local_seo_enabled' => false,
            'robots' => 'index,follow',
            'sections' => $sections,
            'faqs' => [],
        ])->assertOk()
            ->assertJsonPath('service.name', 'Ecografia Addome Completo')
            ->assertJsonPath('service.operationally_active', true)
            ->assertJsonPath('web_profile.public_slug', 'ecografia-addome-completo-pubblica')
            ->assertJsonPath('web_profile.is_web_enabled', false)
            ->assertJsonCount(8, 'web_profile.sections');

        $this->deleteJson("/api/v1/admin/services/{$serviceId}")
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('services', [
            'id' => $serviceId,
            'display_name' => 'Ecografia Addome Completo',
            'is_active' => true,
            'is_web_active' => true,
        ]);
    }

    #[Test]
    public function admin_can_crud_professional_public_profiles_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'doctors-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        $professional = Professional::query()->create([
            'subject_type' => 'individual',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Rossi Mario',
            'area_name' => 'Cardiologia',
            'email' => 'mario.rossi@example.com',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/admin/professional-public-profiles', [
            'professional_id' => $professional->id,
            'slug' => 'dott-mario-rossi',
            'short_bio' => 'Bio breve',
            'bio_content' => 'Biografia completa',
            'is_web_enabled' => true,
            'sort_order' => 1,
        ])->assertCreated();

        $profileId = (int) $created->json('id');

        $this->assertDatabaseHas('professional_public_profiles', [
            'id' => $profileId,
            'slug' => 'dott-mario-rossi',
            'professional_id' => $professional->id,
        ]);

        $this->putJson("/api/v1/admin/professional-public-profiles/{$profileId}", [
            'slug' => 'dott-mario-rossi',
            'short_bio' => 'Bio aggiornata',
            'is_web_enabled' => false,
            'sort_order' => 3,
        ])->assertOk()
            ->assertJsonPath('short_bio', 'Bio aggiornata')
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('is_web_enabled', false)
            ->assertJsonPath('sort_order', 3);

        $this->deleteJson("/api/v1/admin/professional-public-profiles/{$profileId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('professional_public_profiles', [
            'id' => $profileId,
        ]);
    }

    public function admin_can_manage_consent_configuration_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'consent-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        $policyPage = Page::query()->create([
            'title' => 'Privacy policy',
            'slug' => 'privacy-policy',
            'template' => 'legal',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $category = $this->postJson('/api/v1/admin/consent-categories', [
            'key' => 'preferences',
            'name' => 'Preferenze',
            'description' => 'Servizi opzionali',
            'default_state' => false,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 2,
        ])->assertCreated();

        $categoryId = (int) $category->json('id');

        $service = $this->postJson('/api/v1/admin/consent-services', [
            'consent_category_id' => $categoryId,
            'key' => 'youtube_embed',
            'name' => 'YouTube Embed',
            'provider' => 'Google',
            'description' => 'Embed video',
            'purpose' => 'Riproduzione contenuti esterni',
            'privacy_url' => 'https://example.com/privacy',
            'cookie_names' => ['yt-remote-device-id'],
            'retention_period' => '6 months',
            'legal_basis_hint' => 'Consenso',
            'execution_mode' => 'embed',
            'public_config' => ['vendor' => 'youtube'],
            'is_active' => true,
            'sort_order' => 1,
        ])->assertCreated();

        $policy = $this->postJson('/api/v1/admin/consent-policy-versions', [
            'version' => '2026.05',
            'banner_title' => 'Banner',
            'banner_text' => 'Testo banner',
            'preferences_title' => 'Preferenze',
            'preferences_text' => 'Testo preferenze',
            'policy_page_id' => $policyPage->id,
            'cookie_policy_page_id' => $policyPage->id,
            'privacy_policy_page_id' => $policyPage->id,
            'is_active' => true,
            'requires_reconsent' => true,
        ])->assertCreated();

        $serviceId = (int) $service->json('id');
        $policyId = (int) $policy->json('id');

        $this->putJson("/api/v1/admin/consent-categories/{$categoryId}", [
            'key' => 'preferences',
            'name' => 'Preferenze aggiornate',
            'description' => 'Servizi opzionali aggiornati',
            'default_state' => true,
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 3,
        ])->assertOk()
            ->assertJsonPath('name', 'Preferenze aggiornate');

        $this->putJson("/api/v1/admin/consent-services/{$serviceId}", [
            'consent_category_id' => $categoryId,
            'key' => 'youtube_embed',
            'name' => 'YouTube Embed Updated',
            'provider' => 'Google LLC',
            'description' => 'Embed video aggiornato',
            'purpose' => 'Riproduzione contenuti esterni',
            'privacy_url' => 'https://example.com/privacy',
            'cookie_names' => ['yt-remote-device-id'],
            'retention_period' => '12 months',
            'legal_basis_hint' => 'Consenso',
            'execution_mode' => 'embed',
            'public_config' => ['vendor' => 'youtube', 'nocookie' => true],
            'is_active' => false,
            'sort_order' => 4,
        ])->assertOk()
            ->assertJsonPath('name', 'YouTube Embed Updated')
            ->assertJsonPath('is_active', false);

        $this->putJson("/api/v1/admin/consent-policy-versions/{$policyId}", [
            'version' => '2026.05',
            'banner_title' => 'Banner aggiornato',
            'banner_text' => 'Testo banner aggiornato',
            'preferences_title' => 'Preferenze aggiornate',
            'preferences_text' => 'Testo preferenze aggiornato',
            'policy_page_id' => $policyPage->id,
            'cookie_policy_page_id' => $policyPage->id,
            'privacy_policy_page_id' => $policyPage->id,
            'is_active' => false,
            'requires_reconsent' => false,
        ])->assertOk()
            ->assertJsonPath('banner_title', 'Banner aggiornato')
            ->assertJsonPath('requires_reconsent', false);

        $this->deleteJson("/api/v1/admin/consent-services/{$serviceId}")->assertNoContent();
        $this->deleteJson("/api/v1/admin/consent-policy-versions/{$policyId}")->assertNoContent();
        $this->deleteJson("/api/v1/admin/consent-categories/{$categoryId}")->assertNoContent();
    }

    public function admin_can_view_consent_records_and_events_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'consent-audit-admin@example.com',
            'role' => UserRole::Admin,
        ]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));

        $policy = ConsentPolicyVersion::query()->create([
            'version' => '2026.06',
            'is_active' => true,
            'requires_reconsent' => true,
        ]);

        $record = ConsentRecord::query()->create([
            'consent_policy_version_id' => $policy->id,
            'locale' => 'it',
            'source' => 'banner',
            'preferences' => true,
            'analytics' => false,
            'marketing' => false,
            'consented_at' => now(),
            'consent_version_snapshot' => ['version' => '2026.06'],
        ]);

        $change = ConsentPreferenceChange::query()->create([
            'consent_record_id' => $record->id,
            'event_type' => 'save_preferences',
            'payload' => ['analytics' => false],
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/consent-records')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.consent_uuid', $record->consent_uuid);

        $this->getJson("/api/v1/admin/consent-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('status', 'customized');

        $this->getJson('/api/v1/admin/consent-preference-changes')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event_type', 'save_preferences');

        $this->getJson("/api/v1/admin/consent-preference-changes/{$change->id}")
            ->assertOk()
            ->assertJsonPath('payload.analytics', false);
    }
}
