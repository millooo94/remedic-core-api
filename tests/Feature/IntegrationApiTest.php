<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\MiodottoreLoginJob;
use App\Models\ExternalProviderAccount;
use App\Models\ExternalProviderLoginSession;
use App\Models\User;
use App\Services\Marketing\WhatsAppConnectorLauncherService;
use App\Services\Marketing\WhatsAppPuppeteerService;
use App\Services\IntegrationService;
use App\Services\MiodottoreAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_the_supported_integrations_even_when_not_configured(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'provider' => 'miodottore',
                'status' => 'disconnected',
                'password_configured' => false,
            ])
            ->assertJsonFragment([
                'provider' => 'whatsapp',
                'status' => 'disconnected',
                'password_configured' => false,
            ]);
    }

    #[Test]
    public function it_updates_the_whatsapp_integration_without_exposing_cloud_api_fields(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->putJson('/api/v1/integrations/whatsapp', [
            'enabled' => true,
            'api_token' => 'meta-secret-token',
            'phone_number_id' => '12345',
            'business_id' => 'biz-01',
            'sender_number' => '+39095555111',
            'review_template_name' => 'google_review_request',
            'review_template_language' => 'it',
            'test_target' => '+393331234567',
            'notes' => 'Canale marketing principale',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Configurazione WhatsApp aggiornata.')
            ->assertJsonPath('integration.provider', 'whatsapp')
            ->assertJsonPath('integration.enabled', true)
            ->assertJsonPath('integration.notes', 'Canale marketing principale')
            ->assertJsonPath('integration.provider_meta.api_token', null)
            ->assertJsonPath('integration.provider_meta.sender_number', null)
            ->assertJsonPath('integration.provider_meta.review_template_name', null);

        $account = ExternalProviderAccount::query()->where('provider', 'whatsapp')->first();

        $this->assertNotNull($account);
        $this->assertTrue((bool) $account->enabled);
        $this->assertSame('Canale marketing principale', $account->notes);
        $this->assertTrue($account->config_json === null || $account->config_json === []);
    }

    #[Test]
    public function it_returns_a_failed_reconnect_when_the_whatsapp_connector_is_unreachable(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');
        Http::preventStrayRequests();
        Http::fake(static function () {
            throw new ConnectionException('Connector offline');
        });

        $this->mock(WhatsAppConnectorLauncherService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('launch')
                ->atLeast()->once()
                ->andReturn([
                    'started' => false,
                    'message' => 'Launcher non disponibile.',
                ]);
        });

        $this->postJson('/api/v1/integrations/whatsapp/reconnect', [
            'reset_session' => false,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('integration.provider', 'whatsapp')
            ->assertJsonPath('integration.session_status', 'error')
            ->assertJsonPath('message', 'Connettore WhatsApp non raggiungibile. Verificare il processo Puppeteer sul server.');
    }

    #[Test]
    public function it_returns_a_failed_connect_when_the_whatsapp_connector_is_unreachable(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');
        Http::preventStrayRequests();
        Http::fake(static function () {
            throw new ConnectionException('Connector offline');
        });

        $this->mock(WhatsAppConnectorLauncherService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('launch')
                ->atLeast()->once()
                ->andReturn([
                    'started' => false,
                    'message' => 'Launcher non disponibile.',
                ]);
        });

        $this->postJson('/api/v1/integrations/whatsapp/connect', [
            'reset_session' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('action', 'connect')
            ->assertJsonPath('integration.provider', 'whatsapp')
            ->assertJsonPath('integration.session_status', 'error')
            ->assertJsonPath('message', 'Connettore WhatsApp non raggiungibile. Verificare il processo Puppeteer sul server.');
    }

    #[Test]
    public function it_treats_a_qr_ready_whatsapp_connect_state_as_successful(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->mock(WhatsAppPuppeteerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('connect')
                ->once()
                ->with(true)
                ->andReturn([
                    'state' => 'automation_unavailable',
                    'ready' => false,
                    'message' => 'Collegamento WhatsApp avviato, ma il connettore non ha restituito uno stato leggibile.',
                    'qr_required' => false,
                    'qr_code_data_url' => null,
                    'qr_updated_at' => null,
                    'web_state' => null,
                    'queue_depth' => 0,
                    'phone_number' => null,
                    'push_name' => null,
                    'last_error_code' => 'connector_unreachable',
                    'last_error_message' => null,
                    'last_event_at' => now()->toIso8601String(),
                    'last_connected_at' => null,
                ]);

            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'state' => 'qr_required',
                    'ready' => false,
                    'message' => 'QR richiesto: collega WhatsApp Web per abilitare il canale.',
                    'qr_required' => true,
                    'qr_code_data_url' => 'data:image/png;base64,test-qr',
                    'qr_updated_at' => now()->toIso8601String(),
                    'web_state' => 'OPENING',
                    'queue_depth' => 0,
                    'phone_number' => null,
                    'push_name' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'last_event_at' => now()->toIso8601String(),
                    'last_connected_at' => null,
                ]);
        });

        $this->postJson('/api/v1/integrations/whatsapp/connect', [
            'reset_session' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'qr_ready')
            ->assertJsonPath('message', 'Scansiona il QR code con WhatsApp.')
            ->assertJsonPath('integration.session_status', 'connecting')
            ->assertJsonPath('integration.connector_state', 'qr_required')
            ->assertJsonPath('integration.qr_required', true)
            ->assertJsonPath('integration.qr_code_data_url', 'data:image/png;base64,test-qr');
    }

    #[Test]
    public function it_exposes_qr_timeout_whatsapp_connect_failures_as_recoverable_errors(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->mock(WhatsAppPuppeteerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('connect')
                ->once()
                ->with(true)
                ->andReturn([
                    'state' => 'qr_timeout',
                    'ready' => false,
                    'message' => 'QR non generato. Riprova collegamento.',
                    'qr_required' => false,
                    'qr_code_data_url' => null,
                    'qr_updated_at' => null,
                    'web_state' => null,
                    'queue_depth' => 0,
                    'phone_number' => null,
                    'push_name' => null,
                    'last_error_code' => 'qr_timeout',
                    'last_error_message' => 'QR non generato. Riprova collegamento.',
                    'last_event_at' => now()->toIso8601String(),
                    'last_connected_at' => null,
                ]);

            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'state' => 'qr_timeout',
                    'ready' => false,
                    'message' => 'QR non generato. Riprova collegamento.',
                    'qr_required' => false,
                    'qr_code_data_url' => null,
                    'qr_updated_at' => null,
                    'web_state' => null,
                    'queue_depth' => 0,
                    'phone_number' => null,
                    'push_name' => null,
                    'last_error_code' => 'qr_timeout',
                    'last_error_message' => 'QR non generato. Riprova collegamento.',
                    'last_event_at' => now()->toIso8601String(),
                    'last_connected_at' => null,
                ]);
        });

        $this->postJson('/api/v1/integrations/whatsapp/connect', [
            'reset_session' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('integration.session_status', 'error')
            ->assertJsonPath('integration.connector_state', 'qr_timeout')
            ->assertJsonPath('integration.last_error', 'QR non generato. Riprova collegamento.')
            ->assertJsonPath('message', 'QR non generato. Riprova collegamento.');
    }

    #[Test]
    public function it_starts_visible_whatsapp_pairing_and_returns_pairing_started(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->mock(WhatsAppPuppeteerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('pair')
                ->once()
                ->andReturn([
                    'state' => 'qr_ready',
                    'ready' => false,
                    'message' => 'Scansiona il QR code con WhatsApp.',
                    'qr_required' => true,
                    'qr_code_data_url' => 'data:image/png;base64,pair-qr',
                    'qr_updated_at' => now()->toIso8601String(),
                    'web_state' => 'OPENING',
                    'queue_depth' => 0,
                    'phone_number' => null,
                    'push_name' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'last_event_at' => now()->toIso8601String(),
                    'last_connected_at' => null,
                    'process_id' => 3210,
                    'session_path' => 'C:/tmp/whatsapp',
                    'client_generation' => 7,
                ]);

            $mock->shouldReceive('status')
                ->once()
                ->andReturn([
                    'state' => 'qr_ready',
                    'ready' => false,
                    'message' => 'Scansiona il QR code con WhatsApp.',
                    'qr_required' => true,
                    'qr_code_data_url' => 'data:image/png;base64,pair-qr',
                    'qr_updated_at' => now()->toIso8601String(),
                    'web_state' => 'OPENING',
                    'queue_depth' => 0,
                    'phone_number' => null,
                    'push_name' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                    'last_event_at' => now()->toIso8601String(),
                    'last_connected_at' => null,
                    'process_id' => 3210,
                    'session_path' => 'C:/tmp/whatsapp',
                    'client_generation' => 7,
                ]);
        });

        $this->postJson('/api/v1/integrations/whatsapp/pair')
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'pair')
            ->assertJsonPath('status', 'pairing_started')
            ->assertJsonPath('message', 'Si aprira Chrome con WhatsApp Web. Scansiona il QR per collegare il dispositivo.')
            ->assertJsonPath('integration.qr_required', true)
            ->assertJsonPath('integration.qr_code_data_url', 'data:image/png;base64,pair-qr')
            ->assertJsonPath('integration.process_id', 3210)
            ->assertJsonPath('integration.client_generation', 7);
    }

    #[Test]
    public function it_saves_the_global_miodottore_configuration_without_exposing_the_password(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->putJson('/api/v1/integrations/miodottore', [
            'enabled' => true,
            'username' => 'studio@example.test',
            'password' => 'super-segreta',
            'notes' => 'Account principale del gestionale.',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Configurazione MioDottore salvata.')
            ->assertJsonPath('integration.provider', 'miodottore')
            ->assertJsonPath('integration.enabled', true)
            ->assertJsonPath('integration.username', 'studio@example.test')
            ->assertJsonPath('integration.password_configured', true)
            ->assertJsonPath('integration.status', 'session_missing');

        $account = ExternalProviderAccount::query()->where('provider', 'miodottore')->first();

        $this->assertNotNull($account);
        $this->assertSame('studio@example.test', $account->username_encrypted);
        $this->assertSame('Account principale del gestionale.', $account->notes);

        $rawPassword = DB::table('external_provider_accounts')
            ->where('provider', 'miodottore')
            ->value('password_encrypted');

        $this->assertIsString($rawPassword);
        $this->assertNotSame('super-segreta', $rawPassword);
    }

    #[Test]
    public function it_returns_miodottore_status_without_exposing_storage_state_details(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ExternalProviderAccount::query()->create([
            'provider' => 'miodottore',
            'label' => 'MioDottore',
            'enabled' => true,
            'storage_state_path' => 'miodottore/storage-state.json',
            'login_status' => 'session_valid',
            'last_error' => null,
            'last_login_at' => now(),
            'last_session_verified_at' => now(),
            'last_availability_sync_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/integrations/miodottore/status')
            ->assertOk()
            ->assertJsonPath('provider', 'miodottore')
            ->assertJsonPath('login_status', 'session_valid')
            ->assertJsonPath('connected', true)
            ->assertJsonPath('can_sync', true)
            ->assertJsonPath('storage_state_configured', true)
            ->assertJsonPath('message', 'MioDottore collegato')
            ->assertJsonMissingPath('storage_state_path')
            ->assertJsonMissingPath('integration.storage_state_path');
    }

    #[Test]
    public function it_returns_a_clear_placeholder_message_when_testing_connection_without_configuration(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/integrations/miodottore/test-connection')
            ->assertAccepted()
            ->assertJsonPath('status', 'not_configured')
            ->assertJsonPath('message', 'Attiva prima l integrazione MioDottore.')
            ->assertJsonPath('integration.status', 'disconnected');
    }

    #[Test]
    public function it_calls_the_real_availability_sync_endpoint_in_dry_run_mode_by_default(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->mock(IntegrationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncMiodottoreAvailabilities')
                ->once()
                ->with([
                    'days' => 30,
                    'from' => null,
                    'to' => null,
                    'doctor' => null,
                ], false)
                ->andReturn([
                    'success' => true,
                    'message' => 'Dry-run completato. Nessuna scrittura eseguita.',
                    'action' => 'sync_availabilities',
                    'status' => 'dry_run',
                    'requires_reconnect' => false,
                    'dry_run' => true,
                    'write' => false,
                    'output_dir' => 'private/miodottore-access/sync-availabilities/20260616_150000_abcd',
                    'professionals_mapped' => 27,
                    'professionals_unmapped' => 0,
                    'available_imported' => 79,
                    'unavailable_imported' => 3,
                    'rows_deleted' => 0,
                    'rows_inserted' => 0,
                    'plan' => [
                        'from' => '2026-06-16',
                        'to' => '2026-07-16',
                        'mapped_professionals' => 27,
                        'unmapped_professionals' => [],
                        'available_rows' => 79,
                        'unavailable_rows' => 3,
                        'delete_existing_miodottore_rows_in_range' => 0,
                    ],
                    'db_result' => [
                        'written' => false,
                        'deleted_rows' => 0,
                        'inserted_rows' => 0,
                        'preserved_manual_rows' => true,
                    ],
                    'integration' => [
                        'provider' => 'miodottore',
                        'status' => 'session_valid',
                    ],
                ]);
        });

        $this->postJson('/api/v1/integrations/miodottore/sync-availabilities', [
            'days' => 30,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('write', false)
            ->assertJsonPath('professionals_mapped', 27)
            ->assertJsonPath('available_imported', 79)
            ->assertJsonPath('integration.provider', 'miodottore');
    }

    #[Test]
    public function it_returns_requires_reconnect_when_the_provider_session_is_not_valid_for_sync(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->mock(IntegrationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('syncMiodottoreAvailabilities')
                ->once()
                ->with([
                    'days' => 30,
                    'from' => null,
                    'to' => null,
                    'doctor' => null,
                ], false)
                ->andReturn([
                    'success' => false,
                    'message' => 'Sessione MioDottore scaduta. Ricollega MioDottore per sincronizzare.',
                    'action' => 'sync_availabilities',
                    'status' => 'session_expired',
                    'requires_reconnect' => true,
                    'dry_run' => true,
                    'write' => false,
                    'integration' => [
                        'provider' => 'miodottore',
                        'status' => 'session_expired',
                    ],
                ]);
        });

        $this->postJson('/api/v1/integrations/miodottore/sync-availabilities', [
            'days' => 30,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_reconnect', true)
            ->assertJsonPath('status', 'session_expired');
    }

    #[Test]
    public function it_starts_the_assisted_login_flow_and_dispatches_the_queue_job(): void
    {
        Queue::fake();
        config()->set('services.miodottore.login_url', 'https://l.miodottore.it/');

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/integrations/miodottore/assisted-login/start')
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'assisted_login_start')
            ->assertJsonPath('status', 'login_started')
            ->assertJsonPath('connect_mode', 'local_window')
            ->assertJsonPath('requires_user_action', true)
            ->assertJsonPath('integration.session_status', 'connecting');

        $session = ExternalProviderLoginSession::query()
            ->where('provider', 'miodottore')
            ->latest('id')
            ->first();

        $this->assertNotNull($session);
        $this->assertSame(IntegrationService::LOGIN_SESSION_PENDING, $session->status);

        Queue::assertPushed(MiodottoreLoginJob::class, function (MiodottoreLoginJob $job) use ($session): bool {
            return $job->sessionToken === $session->token;
        });
    }

    #[Test]
    public function it_reuses_the_existing_assisted_login_session_without_creating_a_second_one(): void
    {
        Queue::fake();
        config()->set('services.miodottore.login_url', 'https://l.miodottore.it/');

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        ExternalProviderAccount::query()->create([
            'provider' => 'miodottore',
            'label' => 'MioDottore',
            'enabled' => true,
            'login_status' => 'connecting',
        ]);

        $session = ExternalProviderLoginSession::query()->create([
            'provider' => 'miodottore',
            'token' => 'existing-session-token',
            'status' => IntegrationService::LOGIN_SESSION_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/integrations/miodottore/assisted-login/start')
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'login_already_open')
            ->assertJsonPath('session_token', 'existing-session-token')
            ->assertJsonPath('requires_user_action', true);

        $this->assertSame(1, ExternalProviderLoginSession::query()->where('provider', 'miodottore')->count());
        Queue::assertNothingPushed();

        $session->refresh();
        $this->assertSame(IntegrationService::LOGIN_SESSION_ACTIVE, $session->status);
        $this->assertNull($session->last_error);
    }

    #[Test]
    public function a_failed_assisted_login_preserves_the_existing_valid_session_state(): void
    {
        Storage::disk('local')->put('miodottore/storage-state.json', '{}');

        $account = ExternalProviderAccount::query()->create([
            'provider' => 'miodottore',
            'label' => 'MioDottore',
            'enabled' => true,
            'storage_state_path' => 'miodottore/storage-state.json',
            'login_status' => IntegrationService::STATUS_SESSION_VALID,
            'last_login_at' => now()->subHour(),
            'last_session_verified_at' => now()->subHour(),
        ]);

        $session = ExternalProviderLoginSession::query()->create([
            'provider' => 'miodottore',
            'token' => 'failed-assisted-login-valid-session',
            'status' => IntegrationService::LOGIN_SESSION_PENDING,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runInteractiveLogin')
                ->once()
                ->andReturn([
                    'success' => false,
                    'message' => 'La sessione provider non risulta ancora stabilizzata.',
                    'output_dir' => 'miodottore-access/manual-login/test-preserve',
                    'state_path' => 'miodottore/storage-state.json',
                    'result' => [
                        'status' => 'error',
                    ],
                ]);

            $mock->shouldReceive('verifySavedAccess')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'Accesso MioDottore verificato correttamente.',
                    'output_dir' => 'miodottore-access/verify/test-preserve',
                    'state_path' => 'miodottore/storage-state.json',
                    'result' => [
                        'status' => 'session_valid',
                    ],
                ]);
        });

        $result = app(IntegrationService::class)->runMiodottoreLoginFlow($session->token);

        $this->assertFalse($result['success']);

        $account->refresh();
        $session->refresh();

        $this->assertSame(IntegrationService::STATUS_SESSION_VALID, $account->login_status);
        $this->assertSame('miodottore/storage-state.json', $account->storage_state_path);
        $this->assertNull($account->last_error);
        $this->assertSame(IntegrationService::LOGIN_SESSION_ERROR, $session->status);
    }

    #[Test]
    public function a_failed_final_verification_keeps_the_saved_state_path_and_marks_the_session_as_expired(): void
    {
        Storage::disk('local')->put('miodottore/storage-state.json', '{}');

        $session = ExternalProviderLoginSession::query()->create([
            'provider' => 'miodottore',
            'token' => 'failed-assisted-login-expired-session',
            'status' => IntegrationService::LOGIN_SESSION_PENDING,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runInteractiveLogin')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'Accesso MioDottore provider verificato e sessione salvata.',
                    'output_dir' => 'miodottore-access/manual-login/test-expired',
                    'state_path' => 'miodottore/storage-state.json',
                    'result' => [
                        'status' => 'session_valid',
                    ],
                ]);

            $mock->shouldReceive('verifySavedAccess')
                ->twice()
                ->andReturn([
                    'success' => false,
                    'message' => 'La sessione MioDottore non risulta valida per l area gestionale provider.',
                    'output_dir' => 'miodottore-access/verify/test-expired',
                    'state_path' => 'miodottore/storage-state.json',
                    'result' => [
                        'status' => 'session_expired',
                    ],
                ]);
        });

        $result = app(IntegrationService::class)->runMiodottoreLoginFlow($session->token);

        $this->assertFalse($result['success']);
        $this->assertSame(IntegrationService::STATUS_SESSION_EXPIRED, $result['result']['status']);

        $account = ExternalProviderAccount::query()->where('provider', 'miodottore')->first();
        $this->assertNotNull($account);
        $this->assertSame('miodottore/storage-state.json', $account->storage_state_path);
        $this->assertSame(IntegrationService::STATUS_SESSION_EXPIRED, $account->login_status);
        $this->assertNotSame(IntegrationService::STATUS_SESSION_MISSING, $account->login_status);
    }

    #[Test]
    public function the_old_manual_connect_session_routes_are_not_available_anymore(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->postJson('/api/v1/integrations/miodottore/connect-session')->assertNotFound();
        $this->getJson('/api/v1/integrations/miodottore/connect-session/test-token')->assertNotFound();
        $this->postJson('/api/v1/integrations/miodottore/cancel-connect-session')->assertNotFound();
    }
}
