<?php

namespace App\Services\Marketing\Channels;

use App\Models\Patient;
use App\Services\Marketing\MarketingContactNormalizer;
use App\Services\Marketing\TwilioMessagingService;

class SmsChannel implements MarketingChannel
{
    public function __construct(
        private readonly TwilioMessagingService $twilioMessagingService,
        private readonly MarketingContactNormalizer $contactNormalizer,
    ) {
    }

    public function key(): string
    {
        return 'sms';
    }

    public function canContact(Patient $patient): bool
    {
        return $patient->contactable_sms && $this->resolveTarget($patient) !== null;
    }

    public function resolveTarget(Patient $patient): ?string
    {
        return $this->contactNormalizer->normalizePhone($patient->phone);
    }

    public function send(string $target, string $message, ?string $subject = null, array $context = []): MarketingChannelSendResult
    {
        $payload = [
            'To' => $this->contactNormalizer->normalizePhone($target),
            'Body' => $message,
        ];

        $from = trim((string) config('services.twilio.sms_from'));
        if ($from !== '') {
            $payload['From'] = $from;
        }

        $response = $this->twilioMessagingService->send($payload);

        return MarketingChannelSendResult::sent(
            messageId: $response['sid'] ?? null,
            providerStatus: $response['status'] ?? 'sent',
            response: $response,
        );
    }
}
