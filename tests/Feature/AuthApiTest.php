<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function register_creates_a_user_and_sends_a_verification_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Mario',
            'last_name' => 'Rossi',
            'email' => 'Mario.Rossi@example.com',
            'password' => 'ChangeMe123!',
            'password_confirmation' => 'ChangeMe123!',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'mario.rossi@example.com')
            ->assertJsonPath('verification_email_sent', true);

        $user = User::query()->firstOrFail();

        $this->assertSame('Mario', $user->name);
        $this->assertSame('Rossi', $user->last_name);
        $this->assertSame('mario.rossi@example.com', $user->email);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('ChangeMe123!', $user->password));
        $this->assertNull($user->email_verified_at);
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

        $response->assertJsonPath(
            'message',
            'Registrazione completata. Controlla la tua email per confermare l\'account.',
        );
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
            ->assertJsonPath(
                'message',
                'Account creato correttamente, ma non siamo riusciti a inviare l\'email di verifica. Puoi richiederne una nuova dalla pagina di accesso.',
            );

        $this->assertDatabaseHas('users', [
            'email' => 'mario.rossi@example.com',
        ]);
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
            ->assertJsonPath('email', 'admin@example.com');
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
    public function email_verification_redirect_uses_the_configured_frontend_url(): void
    {
        config()->set('app.frontend_url', 'https://remedic-core-ui.vercel.app');

        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($verificationUrl)
            ->assertRedirect('https://remedic-core-ui.vercel.app/login?verification=verified');
    }
}
