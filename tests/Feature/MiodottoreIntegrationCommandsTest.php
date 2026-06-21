<?php

namespace Tests\Feature;

use App\Models\ExternalProviderAccount;
use App\Services\IntegrationService;
use App\Services\MiodottoreAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MiodottoreIntegrationCommandsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_login_command_returns_failure_when_the_saved_session_is_not_verified(): void
    {
        $this->mock(IntegrationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifyMiodottoreAccess')
                ->once()
                ->andReturn([
                    'message' => 'Nessuna sessione MioDottore salvata. Completa prima il collegamento.',
                    'status' => IntegrationService::STATUS_SESSION_MISSING,
                    'integration' => [
                        'status_label' => 'Da collegare',
                    ],
                ]);
        });

        $this->artisan('miodottore:test-login')
            ->expectsOutputToContain('Stato integrazione: Da collegare')
            ->expectsOutputToContain('Nessuna sessione MioDottore salvata. Completa prima il collegamento.')
            ->assertExitCode(1);
    }

    #[Test]
    public function patient_and_appointment_sync_commands_show_the_placeholder_message(): void
    {
        $this->mock(IntegrationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runSyncPlaceholder')
                ->once()
                ->with('sync_patients')
                ->andReturn([
                    'message' => 'Sincronizzazione pazienti MioDottore non ancora implementata. Usera l accesso salvato.',
                    'integration' => [
                        'status_label' => 'Login richiesto',
                    ],
                ]);

            $mock->shouldReceive('runSyncPlaceholder')
                ->once()
                ->with('sync_appointments')
                ->andReturn([
                    'message' => 'Sincronizzazione appuntamenti MioDottore non ancora implementata. Usera l accesso salvato.',
                    'integration' => [
                        'status_label' => 'Login richiesto',
                    ],
                ]);
        });

        $this->artisan('miodottore:sync-patients')
            ->expectsOutputToContain('Stato integrazione: Login richiesto')
            ->expectsOutputToContain('Sincronizzazione pazienti MioDottore non ancora implementata. Usera l accesso salvato.')
            ->assertExitCode(0);

        $this->artisan('miodottore:sync-appointments')
            ->expectsOutputToContain('Stato integrazione: Login richiesto')
            ->expectsOutputToContain('Sincronizzazione appuntamenti MioDottore non ancora implementata. Usera l accesso salvato.')
            ->assertExitCode(0);
    }

    #[Test]
    public function verify_access_command_fails_when_only_the_public_portal_session_is_available(): void
    {
        ExternalProviderAccount::query()->create([
            'provider' => IntegrationService::PROVIDER_MIODOTTORE,
            'label' => 'MioDottore',
            'enabled' => true,
            'storage_state_path' => 'miodottore/storage-state.json',
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifySavedAccess')
                ->once()
                ->andReturn([
                    'success' => false,
                    'message' => 'Accesso effettuato sul portale pubblico MioDottore, ma non valido per l area gestionale provider. Accedi con l account amministrativo/provider corretto.',
                    'output_dir' => 'miodottore-access/verify/test-public',
                    'state_path' => 'miodottore/storage-state.json',
                    'result' => [
                        'status' => 'session_expired',
                        'final_url' => 'https://www.miodottore.it/',
                        'public_homepage_visible' => true,
                        'provider_api_response' => null,
                    ],
                ]);
        });

        $this->artisan('miodottore:verify-access')
            ->expectsOutputToContain('Accesso effettuato sul portale pubblico MioDottore, ma non valido per l area gestionale provider.')
            ->assertExitCode(1);

        $account = ExternalProviderAccount::query()
            ->where('provider', IntegrationService::PROVIDER_MIODOTTORE)
            ->first();

        $this->assertNotNull($account);
        $this->assertSame(IntegrationService::STATUS_SESSION_EXPIRED, $account->login_status);
    }

    #[Test]
    public function verify_access_command_passes_only_when_the_provider_session_is_valid(): void
    {
        ExternalProviderAccount::query()->create([
            'provider' => IntegrationService::PROVIDER_MIODOTTORE,
            'label' => 'MioDottore',
            'enabled' => true,
            'storage_state_path' => 'miodottore/storage-state.json',
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifySavedAccess')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'Accesso MioDottore verificato correttamente.',
                    'output_dir' => 'miodottore-access/verify/test-provider',
                    'state_path' => 'miodottore/storage-state.json',
                    'result' => [
                        'status' => 'session_valid',
                        'final_url' => 'https://docplanner.miodottore.it/#/',
                        'provider_api_response' => [
                            'url' => 'https://docplanner.miodottore.it/api/profile',
                            'status' => 200,
                        ],
                    ],
                ]);
        });

        $this->artisan('miodottore:verify-access')
            ->expectsOutputToContain('Accesso MioDottore verificato correttamente.')
            ->assertExitCode(0);

        $account = ExternalProviderAccount::query()
            ->where('provider', IntegrationService::PROVIDER_MIODOTTORE)
            ->first();

        $this->assertNotNull($account);
        $this->assertSame(IntegrationService::STATUS_SESSION_VALID, $account->login_status);
        $this->assertNotNull($account->last_session_verified_at);
    }
}
