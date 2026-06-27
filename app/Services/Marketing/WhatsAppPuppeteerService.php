<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Channels\MarketingChannelSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppPuppeteerService
{
    public function __construct(
        private readonly WhatsAppConnectorLauncherService $launcherService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(bool $allowAutoLaunch = true): array
    {
        try {
            $response = $this->client()->get('/status');

            if (! $response->successful()) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp raggiunto ma non operativo. Verificare il servizio Puppeteer.',
                    state: 'automation_unavailable',
                );
            }

            $payload = $response->json();

            return is_array($payload)
                ? $this->normalizeStatusPayload($payload)
                : $this->unavailableStatus();
        } catch (ConnectionException) {
            if (! $allowAutoLaunch) {
                return $this->unavailableStatus();
            }

            $launchAttempt = $this->launcherService->launch(true);
            if (! ($launchAttempt['started'] ?? false)) {
                return $this->unavailableStatus(
                    technicalMessage: $launchAttempt['message'] ?? null,
                );
            }

            usleep($this->startupWaitMicroseconds());

            try {
                $retryResponse = $this->client()->get('/status');
                $retryPayload = $retryResponse->json();

                return is_array($retryPayload)
                    ? $this->normalizeStatusPayload($retryPayload)
                    : $this->unavailableStatus(
                        message: 'Connettore WhatsApp avviato, ma non ha restituito uno stato leggibile.',
                        technicalMessage: $launchAttempt['message'] ?? null,
                    );
            } catch (ConnectionException) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp avviato, ma non e ancora raggiungibile. Attendi qualche secondo e riprova.',
                    technicalMessage: $launchAttempt['message'] ?? null,
                );
            }
        } catch (\Throwable $exception) {
            return $this->unavailableStatus(
                message: 'Connettore WhatsApp non disponibile. Verificare il processo Puppeteer sul server.',
                technicalMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function reconnect(bool $resetSession = false): array
    {
        try {
            $response = $this->client()->post('/reconnect', [
                'reset_session' => $resetSession,
            ]);

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->unavailableStatus(
                    message: 'Riconnessione WhatsApp avviata, ma il connettore non ha restituito uno stato leggibile.',
                );
            }

            return $this->normalizeStatusPayload($payload);
        } catch (ConnectionException) {
            $launchAttempt = $this->launcherService->launch(true);
            if (! ($launchAttempt['started'] ?? false)) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp non raggiungibile. Verificare il processo Puppeteer sul server.',
                    technicalMessage: $launchAttempt['message'] ?? null,
                );
            }

            Log::info('WhatsApp connector launched after reconnect connection failure.', $launchAttempt);

            usleep($this->startupWaitMicroseconds());

            try {
                $retryResponse = $this->client()->post('/reconnect', [
                    'reset_session' => $resetSession,
                ]);

                $retryPayload = $retryResponse->json();
                if (! is_array($retryPayload)) {
                    return $this->unavailableStatus(
                        message: 'Connettore WhatsApp avviato, ma non ha restituito uno stato leggibile.',
                        technicalMessage: $launchAttempt['message'] ?? null,
                    );
                }

                return $this->normalizeStatusPayload($retryPayload);
            } catch (ConnectionException) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp avviato, ma non e ancora raggiungibile. Attendi qualche secondo e riprova.',
                    technicalMessage: $launchAttempt['message'] ?? null,
                );
            } catch (\Throwable $exception) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp avviato, ma la riconnessione non e stata completata.',
                    technicalMessage: $exception->getMessage(),
                );
            }
        } catch (\Throwable $exception) {
            return $this->unavailableStatus(
                message: 'Impossibile avviare la riconnessione WhatsApp dal gestionale.',
                technicalMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(bool $resetSession = true): array
    {
        try {
            $response = $this->client()->post('/connect', [
                'reset_session' => $resetSession,
            ]);

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->unavailableStatus(
                    message: 'Collegamento WhatsApp avviato, ma il connettore non ha restituito uno stato leggibile.',
                );
            }

            return $this->normalizeStatusPayload($payload);
        } catch (ConnectionException) {
            $launchAttempt = $this->launcherService->launch(true);
            if (! ($launchAttempt['started'] ?? false)) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp non raggiungibile. Verificare il processo Puppeteer sul server.',
                    technicalMessage: $launchAttempt['message'] ?? null,
                );
            }

            Log::info('WhatsApp connector launched after connect connection failure.', $launchAttempt);

            usleep($this->startupWaitMicroseconds());

            try {
                $retryResponse = $this->client()->post('/connect', [
                    'reset_session' => $resetSession,
                ]);

                $retryPayload = $retryResponse->json();
                if (! is_array($retryPayload)) {
                    return $this->unavailableStatus(
                        message: 'Connettore WhatsApp avviato, ma non ha restituito uno stato leggibile.',
                        technicalMessage: $launchAttempt['message'] ?? null,
                    );
                }

                return $this->normalizeStatusPayload($retryPayload);
            } catch (ConnectionException) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp avviato, ma non e ancora raggiungibile. Attendi qualche secondo e riprova.',
                    technicalMessage: $launchAttempt['message'] ?? null,
                );
            } catch (\Throwable $exception) {
                return $this->unavailableStatus(
                    message: 'Connettore WhatsApp avviato, ma il nuovo QR non e stato generato.',
                    technicalMessage: $exception->getMessage(),
                );
            }
        } catch (\Throwable $exception) {
            return $this->unavailableStatus(
                message: 'Impossibile avviare il collegamento WhatsApp dal gestionale.',
                technicalMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function pair(): array
    {
        $stopAttempt = $this->launcherService->stopProcessOnPort();
        Log::info('Preparing visible WhatsApp pairing flow.', $stopAttempt);

        $launchAttempt = $this->launcherService->launch(false);
        if (! ($launchAttempt['started'] ?? false)) {
            return $this->unavailableStatus(
                message: 'Impossibile avviare il pairing WhatsApp con Chrome visibile.',
                technicalMessage: $launchAttempt['message'] ?? null,
            );
        }

        usleep($this->startupWaitMicroseconds());

        try {
            $response = $this->client()->post('/connect', [
                'reset_session' => true,
            ]);

            $payload = $response->json();
            if (! is_array($payload)) {
                return $this->unavailableStatus(
                    message: 'Pairing WhatsApp avviato, ma il connettore non ha restituito uno stato leggibile.',
                    technicalMessage: $launchAttempt['message'] ?? null,
                );
            }

            return $this->normalizeStatusPayload($payload);
        } catch (ConnectionException) {
            return $this->unavailableStatus(
                message: 'Chrome visibile avviato, ma il connettore WhatsApp non e ancora raggiungibile.',
                technicalMessage: $launchAttempt['message'] ?? null,
            );
        } catch (\Throwable $exception) {
            return $this->unavailableStatus(
                message: 'Pairing WhatsApp non avviato correttamente.',
                technicalMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(): array
    {
        try {
            $response = $this->client()->post('/disconnect');
            $payload = $response->json();

            return is_array($payload)
                ? $this->normalizeStatusPayload($payload)
                : $this->unavailableStatus(
                    message: 'Disconnessione WhatsApp eseguita, ma il connettore non ha restituito uno stato leggibile.',
                    state: 'disconnected',
                );
        } catch (ConnectionException) {
            return $this->unavailableStatus(
                message: 'Connettore WhatsApp non raggiungibile, ma la sessione locale e stata comunque considerata disattivata.',
                state: 'disconnected',
            );
        } catch (\Throwable $exception) {
            return $this->unavailableStatus(
                message: 'Impossibile completare la disconnessione WhatsApp dal gestionale.',
                state: 'error',
                technicalMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resetSession(): array
    {
        try {
            $response = $this->client()->post('/reset-session');
            $payload = $response->json();

            return is_array($payload)
                ? $this->normalizeStatusPayload($payload)
                : $this->unavailableStatus(
                    message: 'Reset sessione WhatsApp eseguito, ma il connettore non ha restituito uno stato leggibile.',
                    state: 'disconnected',
                );
        } catch (ConnectionException) {
            return $this->unavailableStatus(
                message: 'Connettore WhatsApp non raggiungibile, ma la sessione locale e stata comunque considerata resettata.',
                state: 'disconnected',
            );
        } catch (\Throwable $exception) {
            return $this->unavailableStatus(
                message: 'Impossibile completare il reset della sessione WhatsApp dal gestionale.',
                state: 'error',
                technicalMessage: $exception->getMessage(),
            );
        }
    }

    /**
     * @param  array{media_path?:string|null,media_base64?:string|null,media_name?:string|null,media_mime_type?:string|null}  $context
     */
    public function send(string $target, string $message, ?string $subject = null, array $context = []): MarketingChannelSendResult
    {
        try {
            $status = $this->status(false);
            if (! $this->isOperational($status)) {
                Log::warning('WhatsApp send blocked because connector is not operational.', [
                    'state' => $status['state'] ?? null,
                    'normalized_state' => $status['normalized_state'] ?? null,
                    'can_send' => $status['can_send'] ?? false,
                    'message' => $status['message'] ?? null,
                ]);

                return MarketingChannelSendResult::failed(
                    providerStatus: $this->nullableString($status['last_error_code'] ?? null) ?? 'session_not_ready',
                    errorMessage: $this->nullableString($status['message'] ?? null) ?? 'WhatsApp non pronto all invio.',
                    response: $status,
                );
            }

            Log::info('WhatsApp send requested.', [
                'target_tail' => substr(preg_replace('/\D+/', '', $target) ?? '', -4),
                'has_media' => filled($context['media_path'] ?? null) || filled($context['media_base64'] ?? null),
            ]);

            $response = $this->client()->post('/send', [
                'target' => $target,
                'message' => $message,
                'subject' => $subject,
                'media_path' => $this->nullableString($context['media_path'] ?? null),
                'media_base64' => $this->nullableString($context['media_base64'] ?? null),
                'media_name' => $this->nullableString($context['media_name'] ?? null),
                'media_mime_type' => $this->nullableString($context['media_mime_type'] ?? null),
            ]);

            if (! $response->successful()) {
                return MarketingChannelSendResult::failed(
                    providerStatus: 'connector_error',
                    errorMessage: 'Connettore WhatsApp non ha accettato la richiesta di invio.',
                    response: [
                        'http_status' => $response->status(),
                        'body' => $response->json(),
                    ],
                );
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return MarketingChannelSendResult::failed(
                    providerStatus: 'connector_error',
                    errorMessage: 'Connettore WhatsApp ha restituito una risposta non valida.',
                );
            }

            $deliveryStatus = $this->nullableString($payload['delivery_status'] ?? null) ?? 'failed';
            $providerStatus = $this->nullableString($payload['provider_status'] ?? null);

            $result = match ($deliveryStatus) {
                'sent' => ($providerStatus === null || $providerStatus === 'sent')
                    ? MarketingChannelSendResult::sent(
                        messageId: $this->nullableString($payload['message_id'] ?? null),
                        providerStatus: $providerStatus ?? 'sent',
                        response: $this->arrayOrNull($payload['response'] ?? null),
                    )
                    : MarketingChannelSendResult::failed(
                        providerStatus: $providerStatus,
                        errorMessage: $this->nullableString($payload['error_message'] ?? null) ?? 'WhatsApp non ha confermato l\'invio del messaggio.',
                        response: $this->arrayOrNull($payload['response'] ?? null),
                        messageId: $this->nullableString($payload['message_id'] ?? null),
                    ),
                'excluded' => MarketingChannelSendResult::excluded(
                    providerStatus: $providerStatus ?? 'excluded',
                    errorMessage: $this->nullableString($payload['error_message'] ?? 'Destinatario non disponibile su WhatsApp.'),
                    response: $this->arrayOrNull($payload['response'] ?? null),
                ),
                default => MarketingChannelSendResult::failed(
                    providerStatus: $providerStatus ?? 'technical_error',
                    errorMessage: $this->nullableString($payload['error_message'] ?? 'Errore tecnico durante l\'invio WhatsApp.'),
                    response: $this->arrayOrNull($payload['response'] ?? null),
                    messageId: $this->nullableString($payload['message_id'] ?? null),
                ),
            };

            Log::info('WhatsApp send completed.', [
                'delivery_status' => $result->deliveryStatus,
                'provider_status' => $result->providerStatus,
                'message_id' => $result->messageId,
            ]);

            return $result;
        } catch (ConnectionException) {
            Log::error('WhatsApp send failed because connector is unreachable.');

            return MarketingChannelSendResult::failed(
                providerStatus: 'connector_unreachable',
                errorMessage: 'Connettore WhatsApp non raggiungibile. Verificare il processo Puppeteer sul server.',
            );
        } catch (\Throwable $exception) {
            Log::error('WhatsApp send failed with unexpected exception.', [
                'message' => $exception->getMessage(),
            ]);

            return MarketingChannelSendResult::failed(
                providerStatus: 'technical_error',
                errorMessage: 'Errore tecnico durante l\'invio WhatsApp.',
                response: [
                    'technical_message' => $exception->getMessage(),
                ],
            );
        }
    }

    public function ensureReadyForInteractiveUse(): void
    {
        $status = $this->status(false);
        if ($this->isOperational($status)) {
            return;
        }

        throw new RuntimeException((string) ($status['message'] ?? 'WhatsApp non pronto all\'invio.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableStatus(
        string $message = 'Connettore WhatsApp non raggiungibile. Verificare il processo Puppeteer sul server.',
        string $state = 'automation_unavailable',
        ?string $technicalMessage = null,
    ): array {
        $normalizedState = $this->deriveNormalizedState([
            'state' => $state,
            'ready' => false,
            'qr_required' => false,
            'queue_depth' => 0,
        ]);

        return [
            'state' => $state,
            'normalized_state' => $normalizedState,
            'ready' => false,
            'can_send' => false,
            'is_recovering' => $normalizedState === 'recovering',
            'message' => $message,
            'qr_required' => false,
            'qr_code_data_url' => null,
            'qr_updated_at' => null,
            'web_state' => null,
            'queue_depth' => 0,
            'phone_number' => null,
            'push_name' => null,
            'last_error_code' => 'connector_unreachable',
            'last_error_message' => $technicalMessage,
            'last_event_at' => now()->toIso8601String(),
            'last_connected_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeStatusPayload(array $payload): array
    {
        $normalizedState = $payload['normalized_state'] ?? $this->deriveNormalizedState($payload);

        return [
            'state' => $payload['state'] ?? 'automation_unavailable',
            'normalized_state' => $normalizedState,
            'ready' => (bool) ($payload['ready'] ?? false),
            'can_send' => (bool) ($payload['can_send'] ?? $this->deriveCanSend($payload)),
            'is_recovering' => (bool) ($payload['is_recovering'] ?? ($normalizedState === 'recovering')),
            'message' => $payload['message'] ?? 'Stato WhatsApp non disponibile.',
            'qr_required' => (bool) ($payload['qr_required'] ?? false),
            'qr_code_data_url' => $payload['qr_code_data_url'] ?? null,
            'qr_updated_at' => $payload['qr_updated_at'] ?? null,
            'web_state' => $payload['web_state'] ?? null,
            'queue_depth' => (int) ($payload['queue_depth'] ?? 0),
            'phone_number' => $payload['phone_number'] ?? null,
            'push_name' => $payload['push_name'] ?? null,
            'last_error_code' => $payload['last_error_code'] ?? null,
            'last_error_message' => $payload['last_error_message'] ?? null,
            'last_event_at' => $payload['last_event_at'] ?? null,
            'last_connected_at' => $payload['last_connected_at'] ?? null,
            'process_id' => $payload['process_id'] ?? null,
            'session_path' => $payload['session_path'] ?? null,
            'client_generation' => $payload['client_generation'] ?? null,
            'last_qr_available' => (bool) ($payload['last_qr_available'] ?? false),
            'has_local_auth_session' => isset($payload['has_local_auth_session']) ? (bool) $payload['has_local_auth_session'] : null,
            'has_persisted_session' => isset($payload['has_persisted_session']) ? (bool) $payload['has_persisted_session'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    public function isOperational(array $status): bool
    {
        return (bool) ($status['can_send'] ?? $this->deriveCanSend($status));
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function deriveCanSend(array $status): bool
    {
        return (bool) ($status['ready'] ?? false) === true
            && (string) ($status['state'] ?? '') === 'connected';
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function deriveNormalizedState(array $status): string
    {
        $rawState = (string) ($status['state'] ?? '');
        $ready = (bool) ($status['ready'] ?? false);
        $qrRequired = (bool) ($status['qr_required'] ?? false);
        $queueDepth = (int) ($status['queue_depth'] ?? 0);

        if ($ready && $rawState === 'connected') {
            return $queueDepth > 0 ? 'sending' : 'ready';
        }

        if ($qrRequired) {
            return 'qr_required';
        }

        return match ($rawState) {
            'starting', 'initializing', 'waiting_for_scan' => 'starting',
            'authenticated', 'connecting' => 'authenticated',
            'automation_unavailable', 'browser_locked', 'session_cleanup_failed', 'connecting_timeout', 'stale_authenticated_session', 'qr_timeout' => 'recovering',
            'disconnected', 'session_expired', 'auth_failure' => 'disconnected',
            'browser_unavailable', 'ui_incompatible', 'technical_error', 'error' => 'error',
            default => $ready ? 'ready' : 'error',
        };
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $baseUrl = rtrim((string) config('services.whatsapp_puppeteer.base_url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Base URL del connettore WhatsApp non configurata.');
        }

        $timeoutSeconds = (int) config('services.whatsapp_puppeteer.timeout_seconds', 15);
        $token = trim((string) config('services.whatsapp_puppeteer.token'));

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, $timeoutSeconds))
            ->withHeaders($token !== '' ? [
                'X-Connector-Token' => $token,
            ] : []);
    }

    private function startupWaitMicroseconds(): int
    {
        $waitMs = (int) config('services.whatsapp_puppeteer.startup_wait_ms', 2000);

        return max(100, $waitMs) * 1000;
    }

    /**
     * @param  mixed  $value
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
