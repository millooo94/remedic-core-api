<?php

namespace App\Services\Marketing\Channels;

use App\Mail\MarketingCampaignMail;
use App\Models\Patient;
use App\Services\Marketing\MarketingContactNormalizer;
use Illuminate\Support\Facades\Mail;

class EmailChannel implements MarketingChannel
{
    public function __construct(
        private readonly MarketingContactNormalizer $contactNormalizer,
    ) {
    }

    public function key(): string
    {
        return 'email';
    }

    public function canContact(Patient $patient): bool
    {
        return $patient->contactable_email && $this->resolveTarget($patient) !== null;
    }

    public function resolveTarget(Patient $patient): ?string
    {
        return $this->contactNormalizer->normalizeEmail($patient->email);
    }

    public function send(string $target, string $message, ?string $subject = null, array $context = []): MarketingChannelSendResult
    {
        $email = $this->contactNormalizer->normalizeEmail($target);

        Mail::to($email)->send(new MarketingCampaignMail(
            subjectLine: $subject ?: 'Campagna Remedic',
            bodyText: $message,
        ));

        return MarketingChannelSendResult::sent(
            providerStatus: 'sent',
            response: [
                'channel' => 'email',
                'recipient' => $email,
            ],
        );
    }
}
