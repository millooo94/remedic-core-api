<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\AdminAccessRequestMail;
use App\Models\User;
use App\Services\AdminApprovalService;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.primary_admin.email', 'humancaretelemedicine@gmail.com');
    }

    #[Test]
    public function register_creates_a_pending_user_and_sends_verification_and_admin_emails(): void
    {
        Notification::fake();
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Mario',
            'last_name' => 'Rossi',
            'email' => 'Mario.Rossi@example.com',
            'password' => 'ChangeMe123!',
            'password_confirmation' => 'ChangeMe123!',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'mario.rossi@example.com')
            ->assertJsonPath('verification_email_sent', true)
            ->assertJsonPath('approval_request_sent', true);

        $user = User::query()->firstOrFail();

        $this->assertSame('Mario', $user->name);
        $this->assertSame('Rossi', $user->last_name);
        $this->assertSame('mario.rossi@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('ChangeMe123!', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->approval_requested_at);
        $this->assertNull($user->admin_approved_at);
        $this->assertTrue($user->is_active);

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification, array $channels) use ($user): bool {
            $mailMessage = $notification->toMail($user);

            $this->assertSame(['mail'], $channels);
            $this->assertSame('Conferma il tuo indirizzo email Remedic', $mailMessage->subject);
            $this->assertSame([
                'html' => 'mail.verify-email',
                'text' => 'mail.verify-email-text',
            ], $mailMessage->view);

            return true;
        });

        Mail::assertSent(AdminAccessRequestMail::class, function (AdminAccessRequestMail $mail) use ($user): bool {
            return $mail->hasTo('humancaretelemedicine@gmail.com')
                && $mail->user->is($user)
                && str_contains($mail->approvalUrl, '/api/v1/auth/access-requests/')
                && str_contains($mail->rejectUrl, '/api/v1/auth/access-requests/');
        });

        $response->assertJsonPath(
            'message',
            'Registrazione completata. Conferma la tua email e attendi l\'approvazione dell\'amministratore prima di accedere.',
        );
    }

    #[Test]
    public function register_does_not_send_an_admin_warning_for_the_primary_admin_email(): void
    {
        Notification::fake();
        Mail::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Primary',
            'last_name' => 'Admin',
            'email' => 'humancaretelemedicine@gmail.com',
            'password' => 'ChangeMe123!',
            'password_confirmation' => 'ChangeMe123!',
        ])
            ->assertCreated()
            ->assertJsonPath('approval_request_sent', true);

        $user = User::query()->firstOrFail();

        $this->assertNotNull($user->admin_approved_at);
        Mail::assertNothingSent();
    }

    #[Test]
    public function register_returns_validation_errors_for_duplicate_email_after_normalization(): void
    {
        User::factory()->create([
            'email' => 'mario.rossi@example.com',
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mario',
            'last_name' => 'Rossi',
            'email' => '  Mario.Rossi@Example.com ',
            'password' => 'ChangeMe123!',
            'password_confirmation' => 'ChangeMe123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Questa email e gia registrata.');
    }

    #[Test]
    public function register_does_not_fail_when_the_verification_notification_cannot_be_sent(): void
    {
        Mail::fake();

        $this->mock(EmailVerificationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn (User $user, string $context): bool => $user->email === 'mario.rossi@example.com' && $context === 'register')
                ->andReturn(false);
        });

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Mario',
            'last_name' => 'Rossi',
            'email' => 'mario.rossi@example.com',
            'password' => 'ChangeMe123!',
            'password_confirmation' => 'ChangeMe123!',
        ])
            ->assertCreated()
            ->assertJsonPath('verification_email_sent', false)
            ->assertJsonPath('approval_request_sent', true)
            ->assertJsonPath(
                'message',
                'Account creato correttamente, ma non siamo riusciti a inviare l\'email di verifica. Puoi richiederne una nuova dalla pagina di accesso.',
            );

        $this->assertDatabaseHas('users', [
            'email' => 'mario.rossi@example.com',
        ]);
        Mail::assertSent(AdminAccessRequestMail::class);
    }

    #[Test]
    public function admin_can_login_and_read_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'device_name' => 'phpunit',
        ])->assertOk();

        $token = $login->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', 'admin@example.com')
            ->assertJsonPath('is_admin_approved', true)
            ->assertJsonPath('can_access_dashboard', true);
    }

    #[Test]
    public function login_still_succeeds_when_backoffice_role_tables_are_not_available(): void
    {
        config()->set('permission.table_names.roles', 'missing_roles');
        config()->set('permission.table_names.model_has_roles', 'missing_model_has_roles');

        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'role' => UserRole::Admin,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'ChangeMe123!',
            'device_name' => 'phpunit',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.can_access_backoffice', false)
            ->assertJsonPath('user.backoffice_roles', [])
            ->assertJsonPath('user.backoffice_permissions', []);
    }

    #[Test]
    public function login_returns_a_machine_readable_reason_for_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('ChangeMe123!'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'WrongPassword1!',
            'device_name' => 'phpunit',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'invalid_credentials')
            ->assertJsonPath('message', 'Credenziali non valide.');
    }

    #[Test]
    public function login_requires_email_verification_before_any_other_approval_step(): void
    {
        User::factory()
            ->unverified()
            ->create([
                'email' => 'pending@example.com',
                'password' => Hash::make('ChangeMe123!'),
            ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'pending@example.com',
            'password' => 'ChangeMe123!',
            'device_name' => 'phpunit',
        ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'email_not_verified')
            ->assertJsonPath('message', 'Devi prima confermare il tuo indirizzo email.')
            ->assertJsonPath('email', 'pending@example.com');
    }

    #[Test]
    public function login_requires_admin_approval_after_email_verification(): void
    {
        User::factory()
            ->pendingApproval()
            ->create([
                'email' => 'verified@example.com',
                'password' => Hash::make('ChangeMe123!'),
            ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'verified@example.com',
            'password' => 'ChangeMe123!',
            'device_name' => 'phpunit',
        ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'admin_approval_pending')
            ->assertJsonPath('message', 'La tua richiesta e in attesa di approvazione da parte dell\'amministratore.')
            ->assertJsonPath('email', 'verified@example.com');
    }

    #[Test]
    public function login_blocks_inactive_users_even_when_they_are_verified_and_approved(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('ChangeMe123!'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'ChangeMe123!',
            'device_name' => 'phpunit',
        ])
            ->assertForbidden()
            ->assertJsonPath('reason', 'user_inactive')
            ->assertJsonPath('message', 'Il tuo account non e attivo.');
    }

    #[Test]
    public function resend_approval_request_notifies_the_primary_admin_for_verified_pending_users(): void
    {
        Mail::fake();

        $user = User::factory()
            ->pendingApproval()
            ->create([
                'email' => 'verified.pending@example.com',
            ]);

        $this->postJson('/api/v1/auth/approval/resend', [
            'email' => 'verified.pending@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Se la richiesta e ancora in attesa, abbiamo avvisato nuovamente l\'amministratore.');

        Mail::assertSent(AdminAccessRequestMail::class, fn (AdminAccessRequestMail $mail): bool => $mail->hasTo('humancaretelemedicine@gmail.com'));
    }

    #[Test]
    public function cors_preflight_allows_every_configured_frontend_origin(): void
    {
        config()->set('cors.allowed_origins', [
            'http://localhost:4200',
            'https://remedic-core-ui.vercel.app',
        ]);

        $this->call('OPTIONS', '/api/v1/auth/login', server: [
            'HTTP_ORIGIN' => 'https://remedic-core-ui.vercel.app',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, Authorization',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://remedic-core-ui.vercel.app');
    }

    #[Test]
    public function email_verification_redirect_uses_the_configured_frontend_url_and_shows_pending_approval(): void
    {
        config()->set('app.frontend_url', 'https://remedic-core-ui.vercel.app');

        $user = User::factory()
            ->unverified()
            ->pendingApproval()
            ->create();

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($verificationUrl)
            ->assertRedirect('https://remedic-core-ui.vercel.app/login?verification=verified&approval=pending&email='.urlencode($user->email));
    }

    #[Test]
    public function approval_link_marks_the_user_as_approved_and_redirects_to_the_frontend(): void
    {
        config()->set('app.frontend_url', 'https://remedic-core-ui.vercel.app');

        $primaryAdmin = User::factory()->create([
            'email' => 'humancaretelemedicine@gmail.com',
        ]);

        $user = User::factory()
            ->pendingApproval()
            ->create([
                'email' => 'approved.user@example.com',
            ]);

        $approvalUrl = URL::temporarySignedRoute('access-requests.approve', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($approvalUrl)
            ->assertRedirect('https://remedic-core-ui.vercel.app/login?approval=approved&email='.urlencode($user->email));

        $user->refresh();

        $this->assertNotNull($user->admin_approved_at);
        $this->assertNull($user->rejected_at);
        $this->assertSame($primaryAdmin->id, $user->approved_by_user_id);
        $this->assertTrue($user->is_active);
    }

    #[Test]
    public function rejection_link_disables_the_user_and_revokes_active_tokens(): void
    {
        config()->set('app.frontend_url', 'https://remedic-core-ui.vercel.app');

        $primaryAdmin = User::factory()->create([
            'email' => 'humancaretelemedicine@gmail.com',
        ]);

        $user = User::factory()->create([
            'email' => 'rejected.user@example.com',
        ]);

        $user->createToken('phpunit');

        $rejectionUrl = URL::temporarySignedRoute('access-requests.reject', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($rejectionUrl)
            ->assertRedirect('https://remedic-core-ui.vercel.app/login?approval=rejected&email='.urlencode($user->email));

        $user->refresh();

        $this->assertFalse($user->is_active);
        $this->assertNull($user->admin_approved_at);
        $this->assertNotNull($user->rejected_at);
        $this->assertSame($primaryAdmin->id, $user->rejected_by_user_id);
        $this->assertSame(0, $user->tokens()->count());
    }

    #[Test]
    public function admin_approval_service_detects_the_primary_admin_email_case_insensitively(): void
    {
        $service = app(AdminApprovalService::class);

        $this->assertTrue($service->isPrimaryAdminEmail('HumanCareTelemedicine@GMAIL.COM'));
        $this->assertFalse($service->isPrimaryAdminEmail('other@example.com'));
    }
}
