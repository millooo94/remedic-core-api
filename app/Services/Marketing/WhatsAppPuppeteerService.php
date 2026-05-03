<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Channels\MarketingChannelSendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppPuppeteerService
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array
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
            return $this->unavailableStatus();
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
            return $this->unavailableStatus();
        } catch (\Throwable $exception) {
            return $this->unavailableStatus(
                message: 'Impossibile avviare la riconnessione WhatsApp dal gestionale.',
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

            return match ($payload['delivery_status'] ?? 'failed') {
                'sent' => MarketingChannelSendResult::sent(
                    messageId: $this->nullableString($payload['message_id'] ?? null),
                    providerStatus: $this->nullableString($payload['provider_status'] ?? 'sent'),
                    response: $this->arrayOrNull($payload['response'] ?? null),
                ),
                'excluded' => MarketingChannelSendResult::excluded(
                    providerStatus: $this->nullableString($payload['provider_status'] ?? 'excluded'),
                    errorMessage: $this->nullableString($payload['error_message'] ?? 'Destinatario non disponibile su WhatsApp.'),
                    response: $this->arrayOrNull($payload['response'] ?? null),
                ),
                default => MarketingChannelSendResult::failed(
                    providerStatus: $this->nullableString($payload['provider_status'] ?? 'technical_error'),
                    errorMessage: $this->nullableString($payload['error_message'] ?? 'Errore tecnico durante l\'invio WhatsApp.'),
                    response: $this->arrayOrNull($payload['response'] ?? null),
                ),
            };
        } catch (ConnectionException) {
            return MarketingChannelSendResult::failed(
                providerStatus: 'connector_unreachable',
                errorMessage: 'Connettore WhatsApp non raggiungibile. Verificare il processo Puppeteer sul server.',
            );
        } catch (\Throwable $exception) {
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
        $status = $this->status();
        if (($status['ready'] ?? false) === true) {
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
        return [
            'state' => $state,
            'ready' => false,
            'message' => $message,
            'qr_required' => false,
            'qr_code_data_url' => null,
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
        return [
            'state' => $payload['state'] ?? 'automation_unavailable',
            'ready' => (bool) ($payload['ready'] ?? false),
            'message' => $payload['message'] ?? 'Stato WhatsApp non disponibile.',
            'qr_required' => (bool) ($payload['qr_required'] ?? false),
            'qr_code_data_url' => $payload['qr_code_data_url'] ?? null,
            'web_state' => $payload['web_state'] ?? null,
            'queue_depth' => (int) ($payload['queue_depth'] ?? 0),
            'phone_number' => $payload['phone_number'] ?? null,
            'push_name' => $payload['push_name'] ?? null,
            'last_error_code' => $payload['last_error_code'] ?? null,
            'last_error_message' => $payload['last_error_message'] ?? null,
            'last_event_at' => $payload['last_event_at'] ?? null,
            'last_connected_at' => $payload['last_connected_at'] ?? null,
        ];
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
