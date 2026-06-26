<?php

namespace App\Services;

use App\Mail\WhatsAppDisconnectedMail;
use App\Jobs\MiodottoreLoginJob;
use App\Models\ExternalProviderAccount;
use App\Models\ExternalProviderLoginSession;
use App\Services\Marketing\WhatsAppPuppeteerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

class IntegrationService
{
    public const PROVIDER_MIODOTTORE = 'miodottore';

    public const PROVIDER_WHATSAPP = 'whatsapp';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_SESSION_MISSING = 'session_missing';

    public const STATUS_ACCESS_PENDING = 'access_pending';

    public const STATUS_CONNECTING = 'connecting';

    public const STATUS_APP_SELECTION_REQUIRED = 'app_selection_required';

    public const STATUS_SESSION_VALID = 'session_valid';

    public const STATUS_SESSION_EXPIRED = 'session_expired';

    public const STATUS_ERROR = 'error';

    public const LOGIN_SESSION_PENDING = 'pending';

    public const LOGIN_SESSION_ACTIVE = 'active';

    public const LOGIN_SESSION_COMPLETED = 'completed';

    public const LOGIN_SESSION_EXPIRED = 'expired';

    public const LOGIN_SESSION_ERROR = 'error';

    /**
     * @var array<string, array{label: string, description: string}>
     */
    private const PROVIDERS = [
        self::PROVIDER_MIODOTTORE => [
            'label' => 'MioDottore',
            'description' => 'Sincronizzazione di disponibilita, pazienti e appuntamenti da MioDottore',
        ],
        self::PROVIDER_WHATSAPP => [
            'label' => 'WhatsApp',
            'description' => 'Connettore WhatsApp Web con QR code usato da Marketing e automazioni del gestionale',
        ],
    ];

    public function __construct(
        private readonly MiodottoreAccessService $miodottoreAccessService,
        private readonly MiodottoreAvailabilitySyncService $miodottoreAvailabilitySyncService,
        private readonly WhatsAppPuppeteerService $whatsAppPuppeteerService,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return collect(array_keys(self::PROVIDERS))
            ->map(fn (string $provider) => $this->snapshot($provider))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $provider): array
    {
        if ($provider === self::PROVIDER_WHATSAPP) {
            return $this->whatsAppSnapshot();
        }

        $definition = $this->providerDefinition($provider);
        $account = $this->findAccount($provider);
        $latestConnectSession = null;

        if ($provider === self::PROVIDER_MIODOTTORE) {
            $latestConnectSession = $this->latestMiodottoreConnectSession();
            $account = $this->normalizeStaleMiodottoreConnection($account, $latestConnectSession);
        }

        $status = $this->resolveStatus($account);
        $lastError = $status === self::STATUS_SESSION_VALID ? null : $account?->last_error;
        $exposedLatestSession = $status === self::STATUS_SESSION_VALID
            ? $this->sanitizeLatestSessionForConnectedAccount($latestConnectSession)
            : $latestConnectSession;

        return [
            'provider' => $provider,
            'label' => $definition['label'],
            'description' => $definition['description'],
            'enabled' => (bool) ($account?->enabled ?? false),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'session_status' => $status,
            'session_status_label' => $this->statusLabel($status),
            'username' => $account?->username_encrypted,
            'password_configured' => filled($account?->password_encrypted),
            'notes' => $account?->notes,
            'storage_state_configured' => filled($account?->storage_state_path),
            'last_session_verified_at' => optional($account?->last_session_verified_at)->toIso8601String(),
            'last_login_at' => optional($account?->last_login_at)->toIso8601String(),
            'last_availability_sync_at' => optional($account?->last_availability_sync_at)->toIso8601String(),
            'last_patient_sync_at' => optional($account?->last_patient_sync_at)->toIso8601String(),
            'last_appointment_sync_at' => optional($account?->last_appointment_sync_at)->toIso8601String(),
            'last_test_at' => optional($account?->last_test_at)->toIso8601String(),
            'last_error' => $lastError,
            'has_credentials' => filled($account?->username_encrypted) && filled($account?->password_encrypted),
            'latest_login_session' => $exposedLatestSession ? $this->connectSessionSnapshot($exposedLatestSession) : null,
            'provider_meta' => $account?->config_json,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateMiodottore(array $payload): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);

        $account->fill([
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'username_encrypted' => Arr::get($payload, 'username'),
            'notes' => Arr::get($payload, 'notes'),
        ]);

        if (array_key_exists('password', $payload) && filled($payload['password'])) {
            $account->password_encrypted = (string) $payload['password'];
        }

        $account->login_status = $this->resolveStatusForPersistedValues(
            enabled: (bool) $account->enabled,
            storageStatePath: $account->storage_state_path,
            storedLoginStatus: $account->login_status,
        );

        if ($account->login_status !== self::STATUS_ERROR) {
            $account->last_error = null;
        }

        $account->save();

        return $this->snapshot(self::PROVIDER_MIODOTTORE);
    }

    /**
     * @return array<string, mixed>
     */
    public function miodottoreStatus(): array
    {
        $integration = $this->snapshot(self::PROVIDER_MIODOTTORE);
        $sessionStatus = (string) ($integration['session_status'] ?? self::STATUS_DISCONNECTED);
        $connected = $sessionStatus === self::STATUS_SESSION_VALID;

        return [
            'provider' => self::PROVIDER_MIODOTTORE,
            'login_status' => $sessionStatus,
            'connected' => $connected,
            'can_sync' => $connected,
            'storage_state_configured' => (bool) ($integration['storage_state_configured'] ?? false),
            'last_error' => $connected ? null : ($integration['last_error'] ?? null),
            'last_login_at' => $integration['last_login_at'] ?? null,
            'last_session_verified_at' => $integration['last_session_verified_at'] ?? null,
            'last_sync_at' => $integration['last_availability_sync_at'] ?? null,
            'message' => $this->statusMessage($sessionStatus, (bool) ($integration['storage_state_configured'] ?? false)),
            'integration' => $integration,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateWhatsApp(array $payload): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_WHATSAPP);

        $account->fill([
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'notes' => Arr::get($payload, 'notes'),
        ]);

        if (! $account->enabled) {
            $account->login_status = self::STATUS_DISCONNECTED;
            $account->last_error = null;
        }

        $account->save();

        return $this->whatsAppSnapshot();
    }

    /**
     * @return array<string, mixed>
     */
    public function whatsAppStatus(): array
    {
        $integration = $this->whatsAppSnapshot();
        $connected = (bool) ($integration['can_send'] ?? false);

        return [
            'provider' => self::PROVIDER_WHATSAPP,
            'login_status' => $integration['session_status'],
            'connected' => $connected,
            'can_sync' => $connected,
            'operational_state' => $integration['operational_state'] ?? null,
            'can_send' => $connected,
            'is_recovering' => (bool) ($integration['is_recovering'] ?? false),
            'storage_state_configured' => false,
            'last_error' => $integration['last_error'] ?? null,
            'last_login_at' => $integration['last_login_at'] ?? null,
            'last_session_verified_at' => $integration['last_session_verified_at'] ?? null,
            'last_sync_at' => null,
            'message' => $integration['connector_message'] ?? 'Stato WhatsApp da verificare.',
            'integration' => $integration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testWhatsAppConnection(): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_WHATSAPP);
        $status = $this->whatsAppPuppeteerService->status();
        $operationalState = $this->normalizeWhatsAppOperationalState($account, $status);
        $canSend = $this->whatsAppCanSend($status);
        $nextStatus = $this->resolveWhatsAppStatus($account, $status, $operationalState, $canSend);

        $account->forceFill([
            'enabled' => (bool) $account->enabled,
            'login_status' => $nextStatus,
            'last_error' => $nextStatus === self::STATUS_SESSION_VALID ? null : ($status['message'] ?? 'Stato WhatsApp non disponibile.'),
            'last_test_at' => now(),
            'last_session_verified_at' => now(),
            'last_login_at' => $canSend ? now() : $account->last_login_at,
        ])->save();

        return [
            'success' => $canSend,
            'message' => (string) ($status['message'] ?? 'Test WhatsApp completato.'),
            'action' => 'test_connection',
            'status' => $nextStatus,
            'integration' => $this->whatsAppSnapshot(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function connectWhatsApp(bool $resetSession = false): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_WHATSAPP);
        $account->forceFill([
            'enabled' => true,
            'login_status' => self::STATUS_CONNECTING,
            'last_error' => null,
        ])->save();

        $status = $this->whatsAppPuppeteerService->connect($resetSession);
        $operationalState = $this->normalizeWhatsAppOperationalState($account, $status);
        $canSend = $this->whatsAppCanSend($status);
        $nextStatus = $this->resolveWhatsAppStatus($account, $status, $operationalState, $canSend);

        $account->forceFill([
            'login_status' => $nextStatus,
            'last_error' => $nextStatus === self::STATUS_SESSION_VALID || $nextStatus === self::STATUS_CONNECTING
                ? null
                : ($status['message'] ?? null),
            'last_session_verified_at' => now(),
            'last_login_at' => $canSend ? now() : $account->last_login_at,
        ])->save();

        $integration = $this->whatsAppSnapshot();
        $hasQrReady = (bool) ($integration['qr_required'] ?? false) && filled($integration['qr_code_data_url'] ?? null);
        $isWaitingForConnection = in_array(($integration['operational_state'] ?? null), ['starting', 'authenticated', 'qr_required'], true)
            || in_array(($integration['session_status'] ?? null), [self::STATUS_CONNECTING], true);

        if (($integration['can_send'] ?? false) === true || ($integration['session_status'] ?? null) === self::STATUS_SESSION_VALID) {
            return [
                'success' => true,
                'message' => $resetSession
                    ? 'WhatsApp collegato correttamente.'
                    : 'Sessione WhatsApp ripristinata correttamente.',
                'action' => 'connect',
                'status' => self::STATUS_SESSION_VALID,
                'integration' => $integration,
            ];
        }

        if ($hasQrReady) {
            return [
                'success' => true,
                'message' => $resetSession
                    ? 'Scansiona il QR code con WhatsApp.'
                    : 'Sessione non riutilizzabile. Scansiona il QR code con WhatsApp.',
                'action' => 'connect',
                'status' => 'qr_ready',
                'integration' => $integration,
            ];
        }

        if ($isWaitingForConnection) {
            return [
                'success' => true,
                'message' => $resetSession
                    ? (($integration['operational_state'] ?? null) === 'qr_required'
                        ? 'Collegamento WhatsApp in attesa di scansione QR.'
                        : 'Collegamento WhatsApp in inizializzazione. Attendi il QR oppure la conferma finale.')
                    : (($integration['operational_state'] ?? null) === 'authenticated'
                        ? 'Sessione WhatsApp autenticata. Verifica finale in corso prima di rendere il canale operativo.'
                        : 'Verifica sessione WhatsApp in corso. Se necessario verra richiesto un nuovo QR.'),
                'action' => 'connect',
                'status' => self::STATUS_CONNECTING,
                'integration' => $integration,
            ];
        }

        return [
            'success' => false,
            'message' => (string) (($integration['last_error'] ?? null) ?: ($status['message'] ?? 'Collegamento WhatsApp non riuscito.')),
            'action' => 'connect',
            'status' => (string) ($integration['session_status'] ?? $nextStatus),
            'integration' => $integration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pairWhatsApp(): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_WHATSAPP);
        $account->forceFill([
            'enabled' => true,
            'login_status' => self::STATUS_CONNECTING,
            'last_error' => null,
        ])->save();

        $status = $this->whatsAppPuppeteerService->pair();
        $operationalState = $this->normalizeWhatsAppOperationalState($account, $status);
        $canSend = $this->whatsAppCanSend($status);
        $nextStatus = $this->resolveWhatsAppStatus($account, $status, $operationalState, $canSend);

        $account->forceFill([
            'login_status' => $nextStatus,
            'last_error' => $nextStatus === self::STATUS_SESSION_VALID || $nextStatus === self::STATUS_CONNECTING
                ? null
                : ($status['message'] ?? null),
            'last_session_verified_at' => now(),
            'last_login_at' => $canSend ? now() : $account->last_login_at,
        ])->save();

        $integration = $this->whatsAppSnapshot();

        if (($integration['can_send'] ?? false) === true || ($integration['session_status'] ?? null) === self::STATUS_SESSION_VALID) {
            return [
                'success' => true,
                'message' => 'WhatsApp collegato correttamente.',
                'action' => 'pair',
                'status' => self::STATUS_SESSION_VALID,
                'integration' => $integration,
            ];
        }

        if ((bool) ($integration['qr_required'] ?? false) && filled($integration['qr_code_data_url'] ?? null)) {
            return [
                'success' => true,
                'message' => 'Si aprira Chrome con WhatsApp Web. Scansiona il QR per collegare il dispositivo.',
                'action' => 'pair',
                'status' => 'pairing_started',
                'integration' => $integration,
            ];
        }

        if (in_array(($integration['operational_state'] ?? null), ['starting', 'authenticated', 'qr_required'], true)) {
            return [
                'success' => true,
                'message' => 'Si aprira Chrome con WhatsApp Web. Attendi il QR oppure il completamento della verifica sessione.',
                'action' => 'pair',
                'status' => 'pairing_started',
                'integration' => $integration,
            ];
        }

        return [
            'success' => false,
            'message' => (string) (($integration['last_error'] ?? null) ?: ($status['message'] ?? 'Pairing WhatsApp non riuscito.')),
            'action' => 'pair',
            'status' => (string) ($integration['session_status'] ?? self::STATUS_ERROR),
            'integration' => $integration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reconnectWhatsApp(bool $resetSession = false): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_WHATSAPP);
        $account->forceFill([
            'enabled' => true,
            'login_status' => self::STATUS_CONNECTING,
            'last_error' => null,
        ])->save();

        $status = $this->whatsAppPuppeteerService->reconnect($resetSession);
        $operationalState = $this->normalizeWhatsAppOperationalState($account, $status);
        $canSend = $this->whatsAppCanSend($status);
        $nextStatus = $this->resolveWhatsAppStatus($account, $status, $operationalState, $canSend);
        $success = $canSend
            || in_array($operationalState, ['starting', 'authenticated', 'qr_required'], true);

        $account->forceFill([
            'login_status' => $nextStatus,
            'last_error' => $nextStatus === self::STATUS_SESSION_VALID || $nextStatus === self::STATUS_CONNECTING
                ? null
                : ($status['message'] ?? null),
            'last_session_verified_at' => now(),
            'last_login_at' => $canSend ? now() : $account->last_login_at,
        ])->save();

        return [
            'success' => $success,
            'message' => (string) ($status['message'] ?? 'Riconnessione WhatsApp avviata.'),
            'action' => 'reconnect',
            'status' => $nextStatus,
            'integration' => $this->whatsAppSnapshot(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resetWhatsAppSession(): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_WHATSAPP);
        $status = $this->whatsAppPuppeteerService->resetSession();
        $connectorState = (string) ($status['state'] ?? '');
        $failed = in_array($connectorState, ['session_cleanup_failed', 'browser_locked', 'browser_unavailable', 'ui_incompatible', 'technical_error'], true);

        if ($failed) {
            $account->forceFill([
                'enabled' => true,
                'login_status' => self::STATUS_ERROR,
                'last_error' => (string) ($status['message'] ?? 'Reset sessione WhatsApp non riuscito.'),
                'last_session_verified_at' => now(),
            ])->save();

            return [
                'success' => false,
                'message' => (string) ($status['message'] ?? 'Reset sessione WhatsApp non riuscito.'),
                'action' => 'reset_session',
                'status' => self::STATUS_ERROR,
                'integration' => $this->whatsAppSnapshot(),
            ];
        }

        $account->forceFill([
            'enabled' => false,
            'login_status' => self::STATUS_DISCONNECTED,
            'last_error' => null,
            'last_session_verified_at' => now(),
        ])->save();

        return [
            'success' => true,
            'message' => (string) ($status['message'] ?? 'Sessione WhatsApp resettata.'),
            'action' => 'reset_session',
            'status' => self::STATUS_DISCONNECTED,
            'integration' => $this->whatsAppSnapshot(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function terminateWhatsAppConnection(): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_WHATSAPP);
        $status = $this->whatsAppPuppeteerService->disconnect();
        $connectorState = (string) ($status['state'] ?? '');
        $failed = in_array($connectorState, ['session_cleanup_failed', 'browser_locked', 'browser_unavailable', 'ui_incompatible', 'technical_error'], true);

        if ($failed) {
            $account->forceFill([
                'enabled' => true,
                'login_status' => self::STATUS_ERROR,
                'last_error' => (string) ($status['message'] ?? 'Disconnessione WhatsApp non riuscita.'),
                'last_session_verified_at' => now(),
            ])->save();

            return [
                'success' => false,
                'message' => (string) ($status['message'] ?? 'Disconnessione WhatsApp non riuscita.'),
                'action' => 'terminate_connection',
                'status' => self::STATUS_ERROR,
                'integration' => $this->whatsAppSnapshot(),
            ];
        }

        $account->forceFill([
            'enabled' => false,
            'login_status' => self::STATUS_DISCONNECTED,
            'last_error' => null,
            'last_session_verified_at' => now(),
        ])->save();

        return [
            'success' => true,
            'message' => (string) ($status['message'] ?? 'Collegamento WhatsApp disattivato.'),
            'action' => 'terminate_connection',
            'status' => self::STATUS_DISCONNECTED,
            'integration' => $this->whatsAppSnapshot(),
        ];
    }

    /**
     * @return array{success: bool, message: string, action: string, status: string, integration: array<string, mixed>, output_dir?: string, state_path?: string}
     */
    public function backgroundLoginMiodottore(): array
    {
        return $this->startMiodottoreAssistedLogin();
    }

    /**
     * @return array{success: bool, message: string, action: string, status: string, integration: array<string, mixed>, connect_mode: string, session_token?: string, requires_user_action: bool}
     */
    public function startMiodottoreAssistedLogin(): array
    {
        try {
            $session = $this->createMiodottoreConnectSession();
        } catch (\Throwable $exception) {
            $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);
            $account->forceFill([
                'enabled' => true,
                'login_status' => self::STATUS_ERROR,
                'last_error' => $exception->getMessage(),
            ])->save();

            return [
                'success' => false,
                'message' => 'Ricollegamento assistito non disponibile su questo ambiente. Esegui il collegamento dalla postazione autorizzata.',
                'action' => 'assisted_login_start',
                'status' => self::STATUS_ERROR,
                'connect_mode' => 'local_window',
                'requires_user_action' => false,
                'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        return [
            'success' => true,
            'message' => $session['reused']
                ? 'Finestra Chrome MioDottore gia aperta. Completa l accesso nella finestra esistente.'
                : 'Abbiamo avviato il collegamento assistito MioDottore. Completa login, captcha ed eventuale selezione del profilo provider nella finestra Chrome aperta: Remedic Core aggiornera automaticamente lo stato.',
            'action' => 'assisted_login_start',
            'status' => $session['status'],
            'connect_mode' => $session['connect_mode'],
            'session_token' => $session['token'],
            'requires_user_action' => true,
            'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
        ];
    }

    /**
     * @return array{token: string, connect_path: string, connect_mode: string, reused: bool, message: string, status: string}
     */
    public function createMiodottoreConnectSession(): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);

        Log::info('MioDottore connect-session requested.', [
            'provider' => self::PROVIDER_MIODOTTORE,
            'working_directory' => base_path(),
            'queue_connection' => config('queue.default'),
            'queue_name' => 'miodottore',
            'script_path' => base_path('scripts/miodottore/login.mjs'),
            'storage_state_path' => $this->miodottoreAccessService->absoluteStorageStatePath(),
        ]);

        $account->forceFill([
            'enabled' => true,
            'login_status' => self::STATUS_CONNECTING,
            'last_error' => null,
        ])->save();

        if ($this->miodottoreAccessService->loginUrl() === '') {
            $account->forceFill([
                'login_status' => self::STATUS_ERROR,
                'last_error' => 'URL di accesso MioDottore non configurato nell ambiente locale.',
            ])->save();

            throw new InvalidArgumentException('URL di accesso MioDottore non configurato.');
        }

        $reusableSession = ExternalProviderLoginSession::query()
            ->where('provider', self::PROVIDER_MIODOTTORE)
            ->whereIn('status', [self::LOGIN_SESSION_PENDING, self::LOGIN_SESSION_ACTIVE])
            ->latest('id')
            ->get()
            ->tap(function ($sessions): void {
                $sessions->each(function (ExternalProviderLoginSession $session): void {
                    $this->refreshExpiredSession($session);
                });
            })
            ->first(fn (ExternalProviderLoginSession $session): bool => $this->isSessionActive($session));

        if ($reusableSession) {
            Log::info('MioDottore connect-session reused.', [
                'session_id' => $reusableSession->id,
                'token' => $reusableSession->token,
                'status' => $reusableSession->status,
                'expires_at' => optional($reusableSession->expires_at)->toIso8601String(),
            ]);

            return [
                'token' => $reusableSession->token,
                'connect_path' => '/integrations/miodottore/connect/'.$reusableSession->token,
                'connect_mode' => 'local_window',
                'reused' => true,
                'message' => 'Finestra MioDottore gia aperta. Completa l accesso nella finestra esistente.',
                'status' => 'login_already_open',
            ];
        }

        $session = ExternalProviderLoginSession::query()->create([
            'provider' => self::PROVIDER_MIODOTTORE,
            'token' => Str::random(64),
            'status' => self::LOGIN_SESSION_PENDING,
            'started_at' => now(),
            'expires_at' => now()->addSeconds($this->miodottoreAccessService->timeoutSeconds()),
            'created_by' => auth()->id(),
        ]);

        Log::info('MioDottore login session created.', [
            'session_id' => $session->id,
            'token' => $session->token,
            'status' => $session->status,
            'server_now' => now()->toIso8601String(),
            'session_started_at' => optional($session->started_at)->toIso8601String(),
            'expires_at' => optional($session->expires_at)->toIso8601String(),
            'expires_in_seconds' => $session->expires_at ? now()->diffInSeconds($session->expires_at, false) : null,
            'login_timeout_seconds' => $this->miodottoreAccessService->timeoutSeconds(),
            'app_timezone' => config('app.timezone'),
            'php_timezone' => date_default_timezone_get(),
        ]);
        MiodottoreLoginJob::dispatch($session->token)->onQueue('miodottore');

        Log::info('MioDottore connect-session ready.', [
            'session_id' => $session->id,
            'token' => $session->token,
            'connect_path' => '/integrations/miodottore/connect/'.$session->token,
            'connect_mode' => 'local_window',
            'queue_connection' => config('queue.default'),
            'queue_name' => 'miodottore',
        ]);

        return [
            'token' => $session->token,
            'connect_path' => '/integrations/miodottore/connect/'.$session->token,
            'connect_mode' => 'local_window',
            'reused' => false,
            'message' => 'Procedura di accesso MioDottore avviata. Completa il login nella finestra o pagina dedicata.',
            'status' => 'login_started',
        ];
    }

    /**
     * @return array{success: bool, message: string, output_dir: string, state_path: string, result: array<string, mixed>}
     */
    public function runMiodottoreLoginFlow(?string $sessionToken = null): array
    {
        Log::info('MioDottore assisted login flow started.', [
            'session_token' => $sessionToken,
        ]);

        $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);
        $previousState = $this->captureMiodottoreAccountState($account);
        $session = $sessionToken ? $this->findMiodottoreConnectSession($sessionToken) : null;

        if ($session) {
            $session->forceFill([
                'status' => self::LOGIN_SESSION_ACTIVE,
                'started_at' => $session->started_at ?: now(),
                'last_error' => null,
            ])->save();
        }

        $account->forceFill([
            'enabled' => true,
            'login_status' => self::STATUS_ACCESS_PENDING,
            'last_error' => null,
        ])->save();

        try {
            $result = $this->miodottoreAccessService->runInteractiveLogin();

            if ($session) {
                $session->forceFill([
                    'artifacts_path' => $result['output_dir'] ?: $session->artifacts_path,
                ])->save();
            }

            $candidateStatePath = $this->persistStorageStatePathIfAvailable($account, $result['state_path'] ?? null, $previousState);

            if ($result['success']) {
                $verification = $this->miodottoreAccessService->verifySavedAccess();

                if ($verification['success']) {
                    if ($session) {
                        $session->forceFill([
                            'status' => self::LOGIN_SESSION_COMPLETED,
                            'completed_at' => now(),
                            'last_error' => null,
                            'artifacts_path' => $result['output_dir'] ?: $session->artifacts_path,
                        ])->save();
                    }

                    $account->forceFill([
                        'enabled' => true,
                        'storage_state_path' => $verification['state_path'],
                        'login_status' => self::STATUS_SESSION_VALID,
                        'last_login_at' => now(),
                        'last_session_verified_at' => now(),
                        'last_error' => null,
                    ])->save();

                    return [
                        'success' => true,
                        'message' => $verification['message'] ?? 'Accesso MioDottore verificato correttamente.',
                        'output_dir' => (string) ($result['output_dir'] ?? ''),
                        'state_path' => (string) ($verification['state_path'] ?? $result['state_path'] ?? $this->miodottoreAccessService->storageStateRelativePath()),
                        'result' => [
                            'status' => self::STATUS_SESSION_VALID,
                            'action' => 'assisted_login',
                        ],
                    ];
                }

                $result['success'] = false;
                $result['message'] = (string) ($verification['message'] ?? 'La sessione MioDottore salvata non e risultata valida per l area provider.');
                $result['result']['status'] = $verification['result']['status'] ?? self::STATUS_SESSION_EXPIRED;
            }

            if ($session) {
                $session->forceFill([
                    'status' => self::LOGIN_SESSION_ERROR,
                    'completed_at' => now(),
                    'last_error' => $result['message'],
                    'artifacts_path' => $result['output_dir'] ?: $session->artifacts_path,
                ])->save();
            }

            $reconciled = $this->reconcileMiodottoreAccountAfterFailedLogin(
                $result['message'],
                $previousState,
                $candidateStatePath
            );

            return [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Collegamento MioDottore non completato.'),
                'output_dir' => (string) ($result['output_dir'] ?? ''),
                'state_path' => (string) ($candidateStatePath ?? $result['state_path'] ?? $this->miodottoreAccessService->storageStateRelativePath()),
                'result' => [
                    'status' => $reconciled['status'] ?? ($result['result']['status'] ?? self::STATUS_ERROR),
                    'action' => 'assisted_login',
                ],
            ];
        } catch (\Throwable $exception) {
            if ($session) {
                $session->forceFill([
                    'status' => self::LOGIN_SESSION_ERROR,
                    'completed_at' => now(),
                    'last_error' => $exception->getMessage(),
                ])->save();
            }

            $reconciled = $this->reconcileMiodottoreAccountAfterFailedLogin(
                $exception->getMessage(),
                $previousState
            );

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'output_dir' => '',
                'state_path' => (string) ($previousState['storage_state_path'] ?? $this->miodottoreAccessService->storageStateRelativePath()),
                'result' => [
                    'status' => $reconciled['status'] ?? self::STATUS_ERROR,
                    'action' => 'assisted_login',
                ],
            ];
        }
    }

    /**
     * @return array{status: string, preserved_valid_session: bool, message: string}
     */
    public function reconcileMiodottoreAccountAfterFailedLogin(
        string $fallbackMessage,
        array $previousState = [],
        ?string $candidateStatePath = null,
    ): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);
        $preservedStatePath = $candidateStatePath
            ?: ($account->storage_state_path ?: ($previousState['storage_state_path'] ?? null));

        if (filled($preservedStatePath) && $account->storage_state_path !== $preservedStatePath) {
            $account->forceFill([
                'storage_state_path' => $preservedStatePath,
            ])->save();
        }

        if (! filled($preservedStatePath)) {
            $account->forceFill([
                'login_status' => self::STATUS_ERROR,
                'last_login_at' => $previousState['last_login_at'] ?? $account->last_login_at,
                'last_session_verified_at' => now(),
                'last_error' => $fallbackMessage,
            ])->save();

            return [
                'status' => self::STATUS_ERROR,
                'preserved_valid_session' => false,
                'message' => $fallbackMessage,
            ];
        }

        try {
            $verification = $this->miodottoreAccessService->verifySavedAccess();
        } catch (\Throwable $exception) {
            Log::warning('MioDottore saved-session verification failed during reconcile.', [
                'provider' => self::PROVIDER_MIODOTTORE,
                'error' => $exception->getMessage(),
            ]);

            $account->forceFill([
                'storage_state_path' => $preservedStatePath,
                'login_status' => self::STATUS_ERROR,
                'last_login_at' => $previousState['last_login_at'] ?? $account->last_login_at,
                'last_session_verified_at' => now(),
                'last_error' => $fallbackMessage,
            ])->save();

            return [
                'status' => self::STATUS_ERROR,
                'preserved_valid_session' => false,
                'message' => $fallbackMessage,
            ];
        }

        if ($verification['success']) {
            $account->forceFill([
                'login_status' => self::STATUS_SESSION_VALID,
                'storage_state_path' => $verification['state_path'],
                'last_login_at' => now(),
                'last_session_verified_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'status' => self::STATUS_SESSION_VALID,
                'preserved_valid_session' => true,
                'message' => 'Sessione MioDottore salvata ancora valida.',
            ];
        }

        $account->forceFill([
            'storage_state_path' => $preservedStatePath,
            'login_status' => self::STATUS_SESSION_EXPIRED,
            'last_login_at' => $previousState['last_login_at'] ?? $account->last_login_at,
            'last_session_verified_at' => now(),
            'last_error' => $fallbackMessage,
        ])->save();

        return [
            'status' => self::STATUS_SESSION_EXPIRED,
            'preserved_valid_session' => false,
            'message' => $fallbackMessage,
        ];
    }

    /**
     * @return array{success: bool, message: string, action: string, status: string, integration: array<string, mixed>}
     */
    public function terminateMiodottoreConnection(): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);

        ExternalProviderLoginSession::query()
            ->where('provider', self::PROVIDER_MIODOTTORE)
            ->whereIn('status', [self::LOGIN_SESSION_PENDING, self::LOGIN_SESSION_ACTIVE])
            ->update([
                'status' => self::LOGIN_SESSION_EXPIRED,
                'expires_at' => now(),
                'completed_at' => now(),
                'last_error' => 'Collegamento terminato dall utente.',
            ]);

        $this->miodottoreAccessService->clearSavedAccessState();

        $account->forceFill([
            'enabled' => false,
            'storage_state_path' => null,
            'login_status' => self::STATUS_DISCONNECTED,
            'last_error' => null,
            'last_login_at' => null,
            'last_session_verified_at' => null,
        ])->save();

        return [
            'success' => true,
            'message' => 'Collegamento MioDottore terminato.',
            'action' => 'terminate_connection',
            'status' => self::STATUS_DISCONNECTED,
            'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
        ];
    }

    /**
     * @return array{message: string, action: string, status: string, integration: array<string, mixed>}
     */
    public function verifyMiodottoreAccess(): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);
        $status = $this->resolveStatus($account);

        if (! $account->enabled) {
            return [
                'success' => false,
                'message' => 'Attiva prima l integrazione MioDottore.',
                'action' => 'verify_access',
                'status' => self::STATUS_NOT_CONFIGURED,
                'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        if (! filled($account->storage_state_path)) {
            $account->forceFill([
                'login_status' => $status === self::STATUS_ACCESS_PENDING ? self::STATUS_ACCESS_PENDING : self::STATUS_SESSION_MISSING,
                'last_error' => 'Nessuna sessione MioDottore salvata. Completa prima il collegamento.',
            ])->save();

            return [
                'success' => false,
                'message' => 'Completa prima l accesso a MioDottore, poi riprova la verifica.',
                'action' => 'verify_access',
                'status' => $account->login_status,
                'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        $result = $this->miodottoreAccessService->verifySavedAccess();

        $resultStatus = (string) ($result['result']['status'] ?? '');

        if ($result['success']) {
            $account->forceFill([
                'login_status' => self::STATUS_SESSION_VALID,
                'storage_state_path' => $result['state_path'],
                'last_login_at' => now(),
                'last_session_verified_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'success' => true,
                'message' => 'Accesso MioDottore verificato correttamente.',
                'action' => 'verify_access',
                'status' => self::STATUS_SESSION_VALID,
                'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        if ($resultStatus === self::STATUS_APP_SELECTION_REQUIRED) {
            $account->forceFill([
                'login_status' => self::STATUS_APP_SELECTION_REQUIRED,
                'storage_state_path' => $result['state_path'],
                'last_login_at' => now(),
                'last_session_verified_at' => now(),
                'last_error' => $result['message'],
            ])->save();

            return [
                'success' => false,
                'message' => $result['message'],
                'action' => 'verify_access',
                'status' => self::STATUS_APP_SELECTION_REQUIRED,
                'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        $account->forceFill([
            'login_status' => filled($account->storage_state_path)
                ? self::STATUS_SESSION_EXPIRED
                : self::STATUS_SESSION_MISSING,
            'last_session_verified_at' => now(),
            'last_error' => $result['message'],
        ])->save();

        return [
            'success' => false,
            'message' => $result['message'],
            'action' => 'verify_access',
            'status' => $account->login_status,
            'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
        ];
    }

    /**
     * @return array{message: string, action: string, status: string, integration: array<string, mixed>}
     */
    public function runSyncPlaceholder(string $action): array
    {
        $account = $this->ensureAccountRecord(self::PROVIDER_MIODOTTORE);
        $status = $this->resolveStatus($account);

        if (! $account->enabled) {
            return [
                'message' => 'Attiva prima l integrazione MioDottore.',
                'action' => $action,
                'status' => self::STATUS_NOT_CONFIGURED,
                'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        if ($status !== self::STATUS_SESSION_VALID) {
            $message = $status === self::STATUS_APP_SELECTION_REQUIRED
                ? 'Completa prima la selezione dell applicazione MioDottore.'
                : 'Completa prima l accesso a MioDottore.';

            $account->forceFill([
                'last_error' => $message,
            ])->save();

            return [
                'message' => $message,
                'action' => $action,
                'status' => $status,
                'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        $message = match ($action) {
            'sync_availabilities' => 'Sincronizzazione disponibilita MioDottore non ancora implementata. Usera l accesso salvato.',
            'sync_patients' => 'Sincronizzazione pazienti MioDottore non ancora implementata. Usera l accesso salvato.',
            'sync_appointments' => 'Sincronizzazione appuntamenti MioDottore non ancora implementata. Usera l accesso salvato.',
            default => 'Azione MioDottore non ancora implementata.',
        };

        $account->forceFill([
            'last_error' => $message,
        ])->save();

        return [
            'message' => $message,
            'action' => $action,
            'status' => 'not_implemented',
            'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
        ];
    }

    /**
     * @param  array{days?: int|null, from?: string|null, to?: string|null, doctor?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function syncMiodottoreAvailabilities(array $filters = [], bool $write = false): array
    {
        $verification = $this->verifyMiodottoreAccess();
        if (! ($verification['success'] ?? false)) {
            return [
                'success' => false,
                'requires_reconnect' => true,
                'message' => 'Sessione MioDottore scaduta o non verificata. Ricollega MioDottore per sincronizzare.',
                'action' => 'sync_availabilities',
                'status' => $verification['status'] ?? self::STATUS_SESSION_EXPIRED,
                'dry_run' => ! $write,
                'write' => $write,
                'integration' => $verification['integration'] ?? $this->snapshot(self::PROVIDER_MIODOTTORE),
            ];
        }

        $result = $this->miodottoreAvailabilitySyncService->syncNormalizedAvailabilities($filters, $write);
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $dbResult = is_array($result['db_result'] ?? null) ? $result['db_result'] : [];

        return [
            'success' => (bool) ($result['success'] ?? false),
            'requires_reconnect' => false,
            'message' => (string) ($result['message'] ?? ($write
                ? 'Sync disponibilita MioDottore completata.'
                : 'Dry-run completato. Nessuna scrittura eseguita.')),
            'action' => 'sync_availabilities',
            'status' => $write ? 'completed' : 'dry_run',
            'dry_run' => (bool) ($result['dry_run'] ?? ! $write),
            'write' => (bool) ($result['write'] ?? $write),
            'output_dir' => $result['output_dir'] ?? null,
            'professionals_mapped' => (int) ($plan['mapped_professionals'] ?? 0),
            'professionals_unmapped' => count(is_array($plan['unmapped_professionals'] ?? null) ? $plan['unmapped_professionals'] : []),
            'weekly_rules_imported' => (int) ($plan['weekly_rule_rows'] ?? 0),
            'daily_available_exceptions_imported' => (int) ($plan['daily_available_exception_rows'] ?? 0),
            'ignored_unavailable_blocks' => (int) ($plan['ignored_unavailable_blocks'] ?? 0),
            'available_imported' => (int) ($plan['daily_available_exception_rows'] ?? $plan['available_rows'] ?? 0),
            'unavailable_imported' => 0,
            'rows_deleted' => (int) ($dbResult['deleted_rows'] ?? 0),
            'rows_inserted' => (int) ($dbResult['inserted_rows'] ?? 0),
            'plan' => [
                'from' => $plan['from'] ?? null,
                'to' => $plan['to'] ?? null,
                'mapped_professionals' => (int) ($plan['mapped_professionals'] ?? 0),
                'unmapped_professionals' => is_array($plan['unmapped_professionals'] ?? null) ? $plan['unmapped_professionals'] : [],
                'weekly_rule_rows' => (int) ($plan['weekly_rule_rows'] ?? 0),
                'daily_available_exception_rows' => (int) ($plan['daily_available_exception_rows'] ?? 0),
                'ignored_unavailable_blocks' => (int) ($plan['ignored_unavailable_blocks'] ?? 0),
                'delete_existing_miodottore_rules' => (int) ($plan['delete_existing_miodottore_rules'] ?? 0),
                'delete_existing_miodottore_exceptions_in_range' => (int) ($plan['delete_existing_miodottore_exceptions_in_range'] ?? 0),
                'available_rows' => (int) ($plan['available_rows'] ?? 0),
                'unavailable_rows' => (int) ($plan['unavailable_rows'] ?? 0),
                'delete_existing_miodottore_rows_in_range' => (int) ($plan['delete_existing_miodottore_rows_in_range'] ?? 0),
            ],
            'db_result' => [
                'written' => (bool) ($dbResult['written'] ?? false),
                'deleted_rule_rows' => (int) ($dbResult['deleted_rule_rows'] ?? 0),
                'deleted_exception_rows' => (int) ($dbResult['deleted_exception_rows'] ?? 0),
                'deleted_rows' => (int) ($dbResult['deleted_rows'] ?? 0),
                'inserted_rule_rows' => (int) ($dbResult['inserted_rule_rows'] ?? 0),
                'inserted_exception_rows' => (int) ($dbResult['inserted_exception_rows'] ?? 0),
                'inserted_rows' => (int) ($dbResult['inserted_rows'] ?? 0),
                'preserved_manual_rows' => (bool) ($dbResult['preserved_manual_rows'] ?? true),
            ],
            'integration' => $this->snapshot(self::PROVIDER_MIODOTTORE),
        ];
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_DISCONNECTED => 'Da collegare',
            self::STATUS_SESSION_MISSING => 'Da collegare',
            self::STATUS_ACCESS_PENDING => 'Accesso da verificare',
            self::STATUS_CONNECTING => 'In attesa di verifica',
            self::STATUS_APP_SELECTION_REQUIRED => 'Selezione applicazione richiesta',
            self::STATUS_SESSION_VALID => 'Collegata',
            self::STATUS_SESSION_EXPIRED => 'Da rinnovare',
            self::STATUS_ERROR => 'Errore',
            default => 'Da collegare',
        };
    }

    public function statusMessage(string $status, bool $storageStateConfigured = false): string
    {
        return match ($status) {
            self::STATUS_SESSION_VALID => 'MioDottore collegato',
            self::STATUS_SESSION_EXPIRED => 'Sessione MioDottore scaduta',
            self::STATUS_SESSION_MISSING => $storageStateConfigured
                ? 'Sessione MioDottore non verificata'
                : 'MioDottore non collegato',
            self::STATUS_ERROR => 'MioDottore richiede una verifica anti-bot: avvia il ricollegamento assistito.',
            self::STATUS_ACCESS_PENDING,
            self::STATUS_CONNECTING => 'Ricollegamento assistito in corso. Completa login e captcha nella finestra Chrome aperta.',
            self::STATUS_APP_SELECTION_REQUIRED => 'Completa la selezione dell applicazione MioDottore nella finestra Chrome aperta.',
            self::STATUS_NOT_CONFIGURED,
            self::STATUS_DISCONNECTED => 'MioDottore non collegato',
            default => 'Stato MioDottore da verificare',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsAppSnapshot(): array
    {
        $definition = $this->providerDefinition(self::PROVIDER_WHATSAPP);
        $account = $this->findAccount(self::PROVIDER_WHATSAPP);
        $connectorStatus = $this->whatsAppPuppeteerService->status((bool) ($account?->enabled ?? false));
        $operationalState = $this->normalizeWhatsAppOperationalState($account, $connectorStatus);
        $canSend = $this->whatsAppCanSend($connectorStatus);
        $status = $this->resolveWhatsAppStatus($account, $connectorStatus, $operationalState, $canSend);
        $config = $account?->config_json ?? [];
        $lastError = $this->resolveWhatsAppLastError(
            sessionStatus: $status,
            operationalState: $operationalState,
            connectorStatus: $connectorStatus,
            account: $account,
        );
        $account = $this->syncWhatsAppAccountSnapshot(
            account: $account,
            status: $status,
            operationalState: $operationalState,
            canSend: $canSend,
            lastError: $lastError,
            connectorStatus: $connectorStatus,
        );
        $config = $account?->config_json ?? $config;

        return [
            'provider' => self::PROVIDER_WHATSAPP,
            'label' => $definition['label'],
            'description' => $definition['description'],
            'enabled' => (bool) ($account?->enabled ?? false),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'session_status' => $status,
            'session_status_label' => $this->statusLabel($status),
            'username' => null,
            'password_configured' => false,
            'notes' => $account?->notes,
            'storage_state_configured' => false,
            'last_session_verified_at' => optional($account?->last_session_verified_at)->toIso8601String(),
            'last_login_at' => optional($account?->last_login_at)->toIso8601String(),
            'last_availability_sync_at' => optional($account?->last_availability_sync_at)->toIso8601String(),
            'last_patient_sync_at' => optional($account?->last_patient_sync_at)->toIso8601String(),
            'last_appointment_sync_at' => optional($account?->last_appointment_sync_at)->toIso8601String(),
            'last_test_at' => optional($account?->last_test_at)->toIso8601String(),
            'last_error' => $lastError,
            'has_credentials' => false,
            'latest_login_session' => null,
            'provider_meta' => $config,
            'connector_state' => $connectorStatus['state'] ?? null,
            'connector_ready' => (bool) ($connectorStatus['ready'] ?? false),
            'operational_state' => $operationalState,
            'operational_state_label' => $this->whatsAppOperationalStateLabel($operationalState),
            'can_send' => $canSend,
            'is_recovering' => $operationalState === 'recovering',
            'connector_message' => (string) (
                $this->whatsAppConnectorMessage($operationalState, $status, $connectorStatus)
            ),
            'status_hint' => $this->whatsAppStatusHint($operationalState),
            'phone_number' => $connectorStatus['phone_number'] ?? null,
            'push_name' => $connectorStatus['push_name'] ?? null,
            'queue_depth' => (int) ($connectorStatus['queue_depth'] ?? 0),
            'qr_required' => (bool) ($connectorStatus['qr_required'] ?? false),
            'qr_code_data_url' => $connectorStatus['qr_code_data_url'] ?? null,
            'qr_updated_at' => $connectorStatus['qr_updated_at']
                ?? ((($connectorStatus['qr_required'] ?? false) && filled($connectorStatus['qr_code_data_url'] ?? null))
                    ? ($connectorStatus['last_event_at'] ?? now()->toIso8601String())
                    : null),
            'last_connected_at' => $connectorStatus['last_connected_at'] ?? null,
            'process_id' => $connectorStatus['process_id'] ?? null,
            'session_path' => $connectorStatus['session_path'] ?? null,
            'client_generation' => $connectorStatus['client_generation'] ?? null,
            'last_qr_available' => (bool) ($connectorStatus['last_qr_available'] ?? false),
            'has_local_auth_session' => $connectorStatus['has_local_auth_session'] ?? null,
            'has_persisted_session' => $connectorStatus['has_persisted_session'] ?? null,
        ];
    }

    private function syncWhatsAppAccountSnapshot(
        ?ExternalProviderAccount $account,
        string $status,
        ?string $operationalState,
        bool $canSend,
        ?string $lastError,
        array $connectorStatus,
    ): ?ExternalProviderAccount {
        if (! $account) {
            return null;
        }

        $config = $account->config_json ?? [];
        $previousOperational = (bool) ($config['whatsapp_last_known_operational'] ?? false);
        $disconnectNotified = (bool) ($config['whatsapp_disconnect_notified'] ?? false);
        $currentOperational = $canSend;
        $eventAt = now();
        $changed = false;

        if ($account->login_status !== $status) {
            $account->login_status = $status;
            $changed = true;
        }

        if ($account->last_error !== $lastError) {
            $account->last_error = $lastError;
            $changed = true;
        }

        if ($currentOperational) {
            if ($account->last_login_at === null || ! $previousOperational) {
                $account->last_login_at = $eventAt;
                $changed = true;
            }

            if ($account->last_session_verified_at === null || $account->login_status !== self::STATUS_SESSION_VALID || ! $previousOperational) {
                $account->last_session_verified_at = $eventAt;
                $changed = true;
            }

            if ($disconnectNotified || isset($config['whatsapp_disconnect_notified_at'])) {
                $config['whatsapp_disconnect_notified'] = false;
                $config['whatsapp_disconnect_notified_at'] = null;
                $changed = true;
            }
        } elseif ($previousOperational && ! $disconnectNotified) {
            $this->sendWhatsAppDisconnectedMail(
                previousState: self::STATUS_SESSION_VALID,
                currentState: $status,
                phoneNumber: $connectorStatus['phone_number'] ?? null,
                lastError: $lastError,
                eventAt: $eventAt,
            );

            $config['whatsapp_disconnect_notified'] = true;
            $config['whatsapp_disconnect_notified_at'] = $eventAt->toIso8601String();
            $changed = true;
        }

        if (($config['whatsapp_last_known_operational'] ?? null) !== $currentOperational) {
            $config['whatsapp_last_known_operational'] = $currentOperational;
            $changed = true;
        }

        $config['whatsapp_last_known_status'] = $status;
        $config['whatsapp_last_known_operational_state'] = $operationalState;
        $account->config_json = $config;

        if ($changed) {
            $account->save();
        }

        return $account;
    }

    private function sendWhatsAppDisconnectedMail(
        string $previousState,
        string $currentState,
        mixed $phoneNumber,
        ?string $lastError,
        Carbon $eventAt,
    ): void {
        $recipient = trim((string) config('services.whatsapp_puppeteer.disconnect_notification_to', 'humancaretelemedicine@gmail.com'));
        if ($recipient === '') {
            return;
        }

        $appUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $details = [
            'previous_state_label' => $this->statusLabel($previousState),
            'current_state_label' => $this->statusLabel($currentState),
            'phone_number' => filled($phoneNumber) ? (string) $phoneNumber : 'Non disponibile',
            'event_at_label' => $eventAt->timezone(config('app.timezone', 'UTC'))->format('d/m/Y H:i:s'),
            'last_error' => $lastError ?: 'Non disponibile',
            'app_url' => $appUrl !== '' ? $appUrl.'/integrations/whatsapp' : null,
        ];

        try {
            Mail::to($recipient)->send(new WhatsAppDisconnectedMail($details));
        } catch (\Throwable $exception) {
            Log::error('Failed to send WhatsApp disconnected notification.', [
                'recipient' => $recipient,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveWhatsAppStatus(
        ?ExternalProviderAccount $account,
        array $connectorStatus,
        ?string $operationalState = null,
        ?bool $canSend = null,
    ): string
    {
        $operationalState ??= $this->normalizeWhatsAppOperationalState($account, $connectorStatus);
        $canSend ??= $this->whatsAppCanSend($connectorStatus);

        if (! $account?->enabled) {
            return self::STATUS_DISCONNECTED;
        }

        if ($canSend) {
            return self::STATUS_SESSION_VALID;
        }

        $state = (string) ($connectorStatus['state'] ?? '');
        return match ($operationalState) {
            'starting', 'authenticated', 'qr_required' => self::STATUS_CONNECTING,
            'disconnected' => in_array($state, ['session_expired', 'auth_failure'], true)
                ? self::STATUS_SESSION_EXPIRED
                : self::STATUS_DISCONNECTED,
            'recovering', 'error' => in_array($state, ['session_expired', 'auth_failure'], true)
                ? self::STATUS_SESSION_EXPIRED
                : self::STATUS_ERROR,
            default => self::STATUS_ERROR,
        };
    }

    private function normalizeWhatsAppOperationalState(?ExternalProviderAccount $account, array $connectorStatus): string
    {
        if (! $account?->enabled) {
            return 'not_configured';
        }

        $normalized = trim((string) ($connectorStatus['normalized_state'] ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }

        $state = (string) ($connectorStatus['state'] ?? '');
        if ($this->whatsAppCanSend($connectorStatus)) {
            return ((int) ($connectorStatus['queue_depth'] ?? 0)) > 0 ? 'sending' : 'ready';
        }

        if (($connectorStatus['qr_required'] ?? false) === true) {
            return 'qr_required';
        }

        return match ($state) {
            'starting', 'initializing', 'waiting_for_scan' => 'starting',
            'authenticated', 'connecting' => 'authenticated',
            'automation_unavailable', 'browser_locked', 'session_cleanup_failed', 'connecting_timeout', 'stale_authenticated_session', 'qr_timeout' => 'recovering',
            'browser_unavailable', 'ui_incompatible', 'technical_error', 'error' => 'error',
            'disconnected', 'session_expired', 'auth_failure' => 'disconnected',
            default => 'error',
        };
    }

    private function whatsAppOperationalStateLabel(string $state): string
    {
        return match ($state) {
            'not_configured' => 'Non configurato',
            'starting' => 'Avvio in corso',
            'qr_required' => 'QR richiesto',
            'authenticated' => 'Verifica finale',
            'ready' => 'Pronto',
            'sending' => 'Invio in corso',
            'disconnected' => 'Scollegato',
            'recovering' => 'Recupero in corso',
            default => 'Errore',
        };
    }

    private function whatsAppStatusHint(string $operationalState): string
    {
        return match ($operationalState) {
            'not_configured' => 'Clicca Collega a WhatsApp per iniziare il collegamento.',
            'starting' => 'Il connettore si sta avviando.',
            'qr_required' => 'Scansiona il QR con WhatsApp Web.',
            'authenticated' => 'Autenticazione completata, verifica finale in corso.',
            'ready' => 'Il canale puo inviare messaggi.',
            'sending' => 'E in corso almeno un invio WhatsApp.',
            'disconnected' => 'Serve un collegamento o un nuovo QR.',
            'recovering' => 'Il sistema sta tentando di ripulire o recuperare la sessione.',
            default => 'Verifica il connettore o rigenera il QR.',
        };
    }

    private function whatsAppConnectorMessage(string $operationalState, string $sessionStatus, array $connectorStatus): string
    {
        if ($operationalState === 'not_configured') {
            return 'WhatsApp non collegato. Clicca Collega a WhatsApp per generare un nuovo QR code.';
        }

        if ($operationalState === 'ready') {
            return 'WhatsApp Web collegato e pronto all invio.';
        }

        if ($operationalState === 'sending') {
            return 'Invio WhatsApp in corso.';
        }

        if ($operationalState === 'qr_required') {
            return 'QR richiesto: scansiona il codice con WhatsApp per completare il collegamento.';
        }

        if ($operationalState === 'authenticated') {
            return 'Sessione autenticata. Verifica finale in corso prima di rendere il canale operativo.';
        }

        if ($operationalState === 'starting') {
            return 'Avvio del connettore WhatsApp in corso.';
        }

        if ($operationalState === 'recovering') {
            return (string) (($connectorStatus['message'] ?? null) ?: 'Recupero della sessione WhatsApp in corso.');
        }

        if ($sessionStatus === self::STATUS_DISCONNECTED) {
            return 'WhatsApp non collegato. Clicca Collega a WhatsApp per generare un nuovo QR code.';
        }

        return (string) (($connectorStatus['message'] ?? null) ?: 'Stato WhatsApp non disponibile.');
    }

    private function resolveWhatsAppLastError(
        string $sessionStatus,
        string $operationalState,
        array $connectorStatus,
        ?ExternalProviderAccount $account,
    ): ?string {
        if ($sessionStatus === self::STATUS_SESSION_VALID) {
            return null;
        }

        if (in_array($operationalState, ['starting', 'authenticated', 'qr_required', 'not_configured'], true)) {
            return null;
        }

        if ($sessionStatus === self::STATUS_DISCONNECTED && (string) ($connectorStatus['state'] ?? '') === 'disconnected') {
            return null;
        }

        return ($connectorStatus['message'] ?? null) ?: $account?->last_error;
    }

    private function whatsAppCanSend(array $connectorStatus): bool
    {
        if (array_key_exists('can_send', $connectorStatus)) {
            return (bool) $connectorStatus['can_send'];
        }

        return (bool) ($connectorStatus['ready'] ?? false) === true
            && (string) ($connectorStatus['state'] ?? '') === 'connected';
    }

    private function providerDefinition(string $provider): array
    {
        if (! array_key_exists($provider, self::PROVIDERS)) {
            throw new InvalidArgumentException("Provider {$provider} non supportato.");
        }

        return self::PROVIDERS[$provider];
    }

    private function findAccount(string $provider): ?ExternalProviderAccount
    {
        $this->providerDefinition($provider);

        return ExternalProviderAccount::query()
            ->where('provider', $provider)
            ->first();
    }

    private function ensureAccountRecord(string $provider): ExternalProviderAccount
    {
        $definition = $this->providerDefinition($provider);

        return ExternalProviderAccount::query()->firstOrCreate(
            ['provider' => $provider],
            [
                'label' => $definition['label'],
                'enabled' => false,
                'login_status' => self::STATUS_DISCONNECTED,
            ],
        );
    }

    /**
     * @return array{storage_state_path?: string|null, login_status?: string|null, last_login_at?: \Illuminate\Support\Carbon|null, last_session_verified_at?: \Illuminate\Support\Carbon|null}
     */
    private function captureMiodottoreAccountState(ExternalProviderAccount $account): array
    {
        return [
            'storage_state_path' => $account->storage_state_path,
            'login_status' => $account->login_status,
            'last_login_at' => $account->last_login_at,
            'last_session_verified_at' => $account->last_session_verified_at,
        ];
    }

    private function persistStorageStatePathIfAvailable(
        ExternalProviderAccount $account,
        ?string $candidateStatePath,
        array $previousState = [],
    ): ?string {
        $normalizedCandidate = is_string($candidateStatePath) && trim($candidateStatePath) !== ''
            ? trim($candidateStatePath)
            : null;

        if ($normalizedCandidate === null) {
            return $previousState['storage_state_path'] ?? $account->storage_state_path;
        }

        $absoluteCandidatePath = storage_path('app/private/'.$normalizedCandidate);
        if (! is_file($absoluteCandidatePath)) {
            $absoluteCandidatePath = storage_path('app/'.$normalizedCandidate);
        }

        if (! is_file($absoluteCandidatePath)) {
            return $previousState['storage_state_path'] ?? $account->storage_state_path;
        }

        $account->forceFill([
            'storage_state_path' => $normalizedCandidate,
        ])->save();

        return $normalizedCandidate;
    }

    private function resolveStatus(?ExternalProviderAccount $account): string
    {
        return $this->resolveStatusForPersistedValues(
            enabled: (bool) ($account?->enabled ?? false),
            storageStatePath: $account?->storage_state_path,
            storedLoginStatus: $account?->login_status,
        );
    }

    private function resolveStatusForPersistedValues(
        bool $enabled,
        ?string $storageStatePath,
        ?string $storedLoginStatus,
    ): string {
        if (! $enabled) {
            return $storedLoginStatus === self::STATUS_NOT_CONFIGURED
                ? self::STATUS_NOT_CONFIGURED
                : self::STATUS_DISCONNECTED;
        }

        $normalizedStatus = $this->normalizeStoredStatus($storedLoginStatus);
        if (in_array($normalizedStatus, [self::STATUS_ERROR, self::STATUS_ACCESS_PENDING, self::STATUS_CONNECTING, self::STATUS_APP_SELECTION_REQUIRED, self::STATUS_SESSION_EXPIRED], true)) {
            return $normalizedStatus;
        }

        if (filled($storageStatePath)) {
            return self::STATUS_SESSION_VALID;
        }

        return self::STATUS_SESSION_MISSING;
    }

    private function normalizeStoredStatus(?string $storedStatus): ?string
    {
        return match ($storedStatus) {
            'configured' => self::STATUS_SESSION_VALID,
            'login_required' => self::STATUS_ACCESS_PENDING,
            self::STATUS_DISCONNECTED,
            self::STATUS_NOT_CONFIGURED,
            self::STATUS_SESSION_MISSING,
            self::STATUS_ACCESS_PENDING,
            self::STATUS_CONNECTING,
            self::STATUS_APP_SELECTION_REQUIRED,
            self::STATUS_SESSION_VALID,
            self::STATUS_SESSION_EXPIRED,
            self::STATUS_ERROR => $storedStatus,
            default => null,
        };
    }

    private function findMiodottoreConnectSession(string $token): ExternalProviderLoginSession
    {
        $session = ExternalProviderLoginSession::query()
            ->where('provider', self::PROVIDER_MIODOTTORE)
            ->where('token', $token)
            ->first();

        if (! $session) {
            throw new InvalidArgumentException('Sessione di collegamento MioDottore non trovata.');
        }

        return $session;
    }

    private function latestMiodottoreConnectSession(): ?ExternalProviderLoginSession
    {
        $session = ExternalProviderLoginSession::query()
            ->where('provider', self::PROVIDER_MIODOTTORE)
            ->latest('id')
            ->first();

        if (! $session) {
            return null;
        }

        $this->refreshExpiredSession($session);

        return $session->fresh();
    }

    private function normalizeStaleMiodottoreConnection(
        ?ExternalProviderAccount $account,
        ?ExternalProviderLoginSession $latestConnectSession,
    ): ?ExternalProviderAccount {
        if (! $account || $account->login_status !== self::STATUS_CONNECTING) {
            return $account;
        }

        if ($latestConnectSession && $this->isSessionActive($latestConnectSession)) {
            return $account;
        }

        $this->reconcileMiodottoreAccountAfterFailedLogin(
            $account->last_error ?: 'Collegamento non completato. Riprova.'
        );

        return $account->fresh();
    }

    private function refreshExpiredSession(ExternalProviderLoginSession $session): void
    {
        if ($session->status === self::LOGIN_SESSION_COMPLETED || $session->status === self::LOGIN_SESSION_ERROR) {
            return;
        }

        if ($session->expires_at && $session->expires_at->isPast()) {
            $session->forceFill([
                'status' => self::LOGIN_SESSION_EXPIRED,
                'last_error' => $session->last_error ?: 'Collegamento non completato. Verifica che il worker sia attivo e riprova.',
                'completed_at' => $session->completed_at ?: now(),
            ])->save();
        }
    }

    private function isSessionActive(ExternalProviderLoginSession $session): bool
    {
        if (! in_array($session->status, [self::LOGIN_SESSION_PENDING, self::LOGIN_SESSION_ACTIVE], true)) {
            return false;
        }

        return ! $session->expires_at || $session->expires_at->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    public function connectSessionSnapshot(ExternalProviderLoginSession $session): array
    {
        $this->refreshExpiredSession($session);
        $session = $session->fresh() ?? $session;
        $runtimeState = $this->readConnectSessionRuntimeState($session);
        $serverNow = CarbonImmutable::now();
        $startedAtIso = $session->started_at?->copy()->toIso8601String();
        $completedAtIso = $session->completed_at?->copy()->toIso8601String();
        $expiresAtIso = $session->expires_at?->copy()->toIso8601String();
        $isExpired = $session->status === self::LOGIN_SESSION_EXPIRED
            || ($session->expires_at ? $session->expires_at->copy()->isPast() : false);
        $isActive = ! $isExpired
            && in_array($session->status, [self::LOGIN_SESSION_PENDING, self::LOGIN_SESSION_ACTIVE], true);
        $expiresInSeconds = $session->expires_at
            ? max(0, $serverNow->diffInSeconds($session->expires_at->copy(), false))
            : null;

        return [
            'provider' => $session->provider,
            'token' => $session->token,
            'status' => $session->status,
            'started_at' => $startedAtIso,
            'started_at_iso' => $startedAtIso,
            'completed_at' => $completedAtIso,
            'completed_at_iso' => $completedAtIso,
            'expires_at' => $expiresAtIso,
            'expires_at_iso' => $expiresAtIso,
            'last_error' => $session->last_error,
            'artifacts_path' => $session->artifacts_path,
            'connect_mode' => 'local_window',
            'is_active' => $isActive,
            'is_expired' => $isExpired,
            'expires_in_seconds' => $expiresInSeconds,
            'login_timeout_seconds' => $this->miodottoreAccessService->timeoutSeconds(),
            'server_now_iso' => $serverNow->toIso8601String(),
            'app_timezone' => config('app.timezone'),
            'php_timezone' => date_default_timezone_get(),
            'analysis_status' => $runtimeState['analysis_status'] ?? null,
            'next_action' => $runtimeState['next_action'] ?? null,
            'login_visible' => $runtimeState['login_visible'] ?? null,
            'internal_app_visible' => $runtimeState['internal_app_visible'] ?? null,
            'public_homepage_visible' => $runtimeState['public_homepage_visible'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readConnectSessionRuntimeState(ExternalProviderLoginSession $session): array
    {
        if (! filled($session->artifacts_path)) {
            return [];
        }

        $basePath = storage_path('app/private/'.$session->artifacts_path);
        $candidates = [
            $basePath.DIRECTORY_SEPARATOR.'04-current-state.json',
            $basePath.DIRECTORY_SEPARATOR.'05-public-homepage-detected.json',
            $basePath.DIRECTORY_SEPARATOR.'result.json',
        ];

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($candidate), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function sanitizeLatestSessionForConnectedAccount(?ExternalProviderLoginSession $session): ?ExternalProviderLoginSession
    {
        if (! $session) {
            return null;
        }

        if (in_array($session->status, [self::LOGIN_SESSION_ACTIVE, self::LOGIN_SESSION_PENDING, self::LOGIN_SESSION_COMPLETED], true)) {
            return $session;
        }

        return null;
    }
}
