<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\ApplicationType;
use App\Models\JobApplication;
use App\Models\Page;
use App\Models\User;
use App\Services\ApplicationTypeInitializer;
use App\Services\PageContentService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationsAndCareersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function application_types_are_initialized_without_overwriting_editorial_changes_and_reordered(): void
    {
        app(ApplicationTypeInitializer::class)->initialize();
        $type = ApplicationType::query()->firstOrFail();
        $type->update(['name' => 'Nome editoriale', 'is_active' => false]);
        app(ApplicationTypeInitializer::class)->initialize();

        $this->assertSame(5, ApplicationType::count());
        $this->assertSame('Nome editoriale', $type->fresh()->name);
        $this->assertFalse($type->fresh()->is_active);
        $ids = ApplicationType::query()->publicOrder()->pluck('id')->all();
        $reversed = array_reverse($ids);
        $this->postJson('/api/v1/application-types/reorder', ['ids' => $reversed])->assertOk();
        $this->assertSame($reversed, ApplicationType::query()->publicOrder()->pluck('id')->all());
        $this->getJson('/api/v1/public/application-types')->assertOk()->assertJsonMissing(['is_active' => false]);
    }

    #[Test]
    public function public_submission_keeps_private_cv_and_snapshot_while_admin_can_download_it(): void
    {
        Storage::fake('local');
        $type = ApplicationType::factory()->create(['name' => 'Medici specialisti', 'is_active' => true]);

        $response = $this->post('/api/v1/public/job-applications', [
            'first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => 'ada@example.test', 'phone' => null,
            'application_type_id' => $type->id, 'message' => 'Mi candido.',
            'cv' => UploadedFile::fake()->create('curriculum.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonStructure(['data' => ['id', 'submitted_at']])->assertJsonMissing(['cv_path', 'status', 'message']);
        $application = JobApplication::query()->sole();
        $this->assertSame('Medici specialisti', $application->application_type_name_snapshot);
        $this->assertSame('new', $application->status->value);
        Storage::disk('local')->assertExists($application->cv_path);
        $this->getJson("/api/v1/job-applications/{$application->id}/cv")->assertOk();

        $type->update(['is_active' => false]);
        $this->postJson('/api/v1/public/job-applications', [
            'first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => 'ada2@example.test',
            'application_type_id' => $type->id, 'message' => 'Mi candido.',
        ])->assertUnprocessable();
    }

    #[Test]
    public function careers_is_a_closed_draft_page_with_runtime_active_types_and_semantic_privacy(): void
    {
        ApplicationType::factory()->create(['name' => 'Attivo', 'is_active' => true, 'sort_order' => 1]);
        ApplicationType::factory()->create(['name' => 'Nascosto', 'is_active' => false, 'sort_order' => 0]);
        Page::query()->updateOrCreate(['internal_key' => 'privacy'], ['slug' => 'privacy', 'title' => 'Privacy', 'is_active' => true, 'published_at' => now()->subMinute(), 'faq_enabled' => false]);
        $careers = Page::query()->create(['internal_key' => Page::CAREERS_INTERNAL_KEY, 'slug' => Page::CAREERS_SLUG, 'title' => 'Lavora con noi', 'is_active' => true, 'published_at' => null, 'faq_enabled' => false]);
        app(PageContentService::class)->initializeMissingSections($careers);

        $this->assertSame(['hero', 'professional_profiles', 'what_we_look_for', 'application'], $careers->sections()->ordered()->pluck('key')->all());
        $this->assertSame(0, $careers->faqs()->count());
        $this->getJson('/api/v1/public/pages/lavora-con-noi')->assertNotFound();
        $careers->update(['published_at' => now()->subMinute()]);
        $this->getJson('/api/v1/public/pages/lavora-con-noi')->assertOk()
            ->assertJsonPath('data.sections.3.data.action.type', 'open_application_form')
            ->assertJsonPath('data.sections.3.data.privacy.href', '/privacy')
            ->assertJsonPath('data.sections.3.data.application_types.0.name', 'Attivo')
            ->assertJsonMissing(['Nascosto']);
    }
}
