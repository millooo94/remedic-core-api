<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

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
