<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioMessagingService
{
    /**
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        $sid = trim((string) config('services.twilio.account_sid'));
        $token = trim((string) config('services.twilio.auth_token'));

        if ($sid === '' || $token === '') {
            throw new RuntimeException('Credenziali Twilio mancanti. Configura account SID e auth token.');
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post(
                sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $sid),
                $payload,
            );

        if ($response->failed()) {
            throw new RuntimeException('Invio Twilio fallito: '.$response->body());
        }

        return $response->json() ?: [];
    }
}
