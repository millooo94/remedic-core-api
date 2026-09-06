<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Mail\CareerApplicationCandidateMail;
use App\Mail\CareerApplicationInternalMail;
use App\Models\ApplicationSetting;
use App\Models\ApplicationType;
use App\Models\InternalNotification;
use App\Models\JobApplication;
use App\Models\Page;
use App\Models\User;
use App\Services\ApplicationTypeInitializer;
use App\Services\PageContentService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
        $publicIds = ApplicationType::query()->publicOrder()->pluck('public_id')->all();
        $reversed = array_reverse($publicIds);
        $this->postJson('/api/v1/application-types/reorder', ['public_ids' => $reversed])->assertOk();
        $this->assertSame($reversed, ApplicationType::query()->publicOrder()->pluck('public_id')->all());
        $this->getJson('/api/v1/public/application-types')->assertOk()->assertJsonMissing(['is_active' => false]);
    }

    #[Test]
    public function public_submission_keeps_private_cv_and_snapshot_while_admin_can_download_it(): void
    {
        Storage::fake('local');
        $type = ApplicationType::factory()->create(['name' => 'Medici specialisti', 'is_active' => true]);

        $response = $this->post('/api/v1/public/career-applications', [
            'first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => 'ada@example.test', 'phone' => null,
            'application_type' => $type->key, 'message' => 'Mi candido con interesse.', 'locale' => 'it', 'privacy_consent' => true,
            'cv' => UploadedFile::fake()->create('curriculum.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonStructure(['data' => ['reference', 'submitted_at']])->assertJsonMissing(['cv_path', 'status', 'message']);
        $application = JobApplication::query()->sole();
        $this->assertSame('Medici specialisti', $application->application_type_name_snapshot);
        $this->assertSame('new', $application->status->value);
        Storage::disk('local')->assertExists($application->cv_path);
        $this->getJson("/api/v1/career-applications/{$application->public_id}/cv")->assertOk();

        $type->update(['is_active' => false]);
        $this->postJson('/api/v1/public/career-applications', [
            'first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => 'ada2@example.test',
            'application_type' => $type->key, 'message' => 'Mi candido con interesse.', 'privacy_consent' => true,
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
            ->assertJsonPath('data.sections.3.data.cta_label', 'Invia la tua candidatura')
            ->assertJsonPath('data.sections.3.data.privacy.href', '/privacy')
            ->assertJsonPath('data.sections.3.data.application_types.0.label', 'Attivo')
            ->assertJsonMissing(['Nascosto']);
    }

    #[Test]
    public function public_submission_validates_private_cv_privacy_locale_and_mail_configuration(): void
    {
        Storage::fake('local');
        Mail::fake();
        $type = ApplicationType::factory()->create(['name' => 'Collaborazioni', 'is_active' => true]);
        ApplicationSetting::query()->firstOrCreate(['id' => 1])->update(['career_recipient_email' => 'hr@example.test']);

        foreach ([
            ['it', 'ada.it@example.test', 'Abbiamo ricevuto la tua candidatura — Remedic'],
            ['en', 'ada.en@example.test', 'We received your application — Remedic'],
            ['es', 'ada.es@example.test', 'Hemos recibido tu candidatura — Remedic'],
            ['fr', 'ada.fr@example.test', 'Nous avons reçu votre candidature — Remedic'],
        ] as [$locale, $email, $subject]) {
            $this->post('/api/v1/public/career-applications', [
                'first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => $email,
                'application_type' => $type->key, 'message' => 'Mi candido con interesse.',
                'locale' => $locale, 'privacy_consent' => true,
                'cv' => UploadedFile::fake()->create('curriculum-'.$locale.'.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertCreated();

            Mail::assertSent(CareerApplicationCandidateMail::class, function (CareerApplicationCandidateMail $mail) use ($email, $subject): bool {
                return $mail->hasTo($email) && $mail->envelope()->subject === $subject;
            });
        }
        RateLimiter::clear('career-application:127.0.0.1');

        $application = JobApplication::query()->where('email', 'ada.it@example.test')->sole();
        $this->assertNotEmpty($application->public_id);
        $this->assertNull($application->first_opened_at);
        $this->assertNotNull($application->privacy_consent_at);
        $this->assertSame('application/pdf', $application->cv_mime_type);
        $this->assertSame(120 * 1024, $application->cv_size_bytes);
        $this->assertMatchesRegularExpression('#^career-applications/cv/.+#', (string) $application->cv_path);
        Storage::disk('local')->assertExists($application->cv_path);
        Mail::assertSent(CareerApplicationInternalMail::class, fn (CareerApplicationInternalMail $mail): bool => $mail->hasTo('hr@example.test'));

        foreach ([['curriculum.doc', 'application/msword'], ['curriculum.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']] as [$filename, $mimeType]) {
            $this->withServerVariables(['REMOTE_ADDR' => $filename === 'curriculum.doc' ? '192.0.2.3' : '192.0.2.4'])
                ->post('/api/v1/public/career-applications', [
                    ...$this->submissionPayload($type, ['email' => $filename.'@example.test']),
                    'cv' => UploadedFile::fake()->create($filename, 120, $mimeType),
                ], ['Accept' => 'application/json'])
                ->assertCreated();
        }

        $payload = ['first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => 'invalid@example.test', 'application_type' => $type->key, 'message' => 'too short'];
        $this->postJson('/api/v1/public/career-applications', $payload)->assertUnprocessable()->assertJsonValidationErrors(['privacy_consent', 'message']);
        RateLimiter::clear('career-application:127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->post('/api/v1/public/career-applications', [...$payload, 'privacy_consent' => true, 'cv' => UploadedFile::fake()->create('unsafe.txt', 10, 'text/plain')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['cv']);
        RateLimiter::clear('career-application:127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->post('/api/v1/public/career-applications', [...$payload, 'privacy_consent' => true, 'message' => 'Messaggio valido per candidatura.', 'cv' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['cv']);
    }

    #[Test]
    public function first_opened_is_distinct_from_notifications_and_view_permission_controls_access(): void
    {
        Mail::fake();
        $type = ApplicationType::factory()->create(['name' => 'Area organizzativa', 'is_active' => true]);
        $this->postJson('/api/v1/public/career-applications', $this->submissionPayload($type))->assertCreated();
        $application = JobApplication::query()->sole();

        $this->getJson('/api/v1/career-applications')->assertOk();
        $this->assertNull($application->fresh()->first_opened_at);
        $notification = InternalNotification::query()->where('source_public_id', $application->public_id)->sole();
        $this->getJson('/api/v1/admin/notifications')->assertOk();
        $this->patchJson('/api/v1/admin/notifications/'.$notification->public_id.'/read')->assertOk();
        $this->assertNull($application->fresh()->first_opened_at);

        $this->getJson('/api/v1/career-applications/summary')->assertOk()->assertJsonPath('data.unopened_count', 1);
        $this->getJson('/api/v1/career-applications/'.$application->public_id)->assertOk()->assertJsonPath('is_unopened', false);
        $openedAt = $application->fresh()->first_opened_at;
        $this->assertNotNull($openedAt);
        $this->getJson('/api/v1/career-applications/'.$application->public_id)->assertOk();
        $this->assertTrue($openedAt->equalTo($application->fresh()->first_opened_at));
        $this->getJson('/api/v1/career-applications/summary')->assertOk()->assertJsonPath('data.unopened_count', 0);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(AdminPermission::VIEW_CAREER_APPLICATIONS->value);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/career-applications')->assertOk();
        $this->patchJson('/api/v1/career-applications/'.$application->public_id.'/status', ['status' => 'archived'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/career-applications')->assertForbidden();
    }

    #[Test]
    public function missing_internal_recipient_keeps_persistence_candidate_mail_and_notification_safe(): void
    {
        Mail::fake();
        ApplicationSetting::query()->firstOrCreate(['id' => 1])->update(['career_recipient_email' => null]);
        $type = ApplicationType::factory()->create(['name' => 'Candidatura spontanea', 'is_active' => true]);

        $this->postJson('/api/v1/public/career-applications', $this->submissionPayload($type, ['email' => 'safe@example.test']))->assertCreated();

        $this->assertDatabaseCount('job_applications', 1);
        $this->assertDatabaseCount('internal_notifications', 1);
        Mail::assertSent(CareerApplicationCandidateMail::class, fn (CareerApplicationCandidateMail $mail): bool => $mail->hasTo('safe@example.test'));
        Mail::assertNotSent(CareerApplicationInternalMail::class);
    }

    #[Test]
    public function public_submission_is_rate_limited_by_client_ip(): void
    {
        Mail::fake();
        $type = ApplicationType::factory()->create(['name' => 'Professionisti sanitari', 'is_active' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);

        for ($index = 1; $index <= 5; $index++) {
            $this->postJson('/api/v1/public/career-applications', $this->submissionPayload($type, ['email' => "candidate{$index}@example.test"]))->assertCreated();
        }

        $this->postJson('/api/v1/public/career-applications', $this->submissionPayload($type, ['email' => 'limited@example.test']))->assertStatus(429);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function submissionPayload(ApplicationType $type, array $overrides = []): array
    {
        return [...[
            'first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => 'ada@example.test', 'phone' => null,
            'application_type' => $type->key, 'message' => 'Mi candido con interesse.', 'locale' => 'it', 'privacy_consent' => true,
        ], ...$overrides];
    }
}
