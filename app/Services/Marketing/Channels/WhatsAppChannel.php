<?php

namespace App\Services\Marketing\Channels;

use App\Models\Patient;
use App\Services\Marketing\MarketingContactNormalizer;
use App\Services\Marketing\WhatsAppPuppeteerService;

class WhatsAppChannel implements MarketingChannel
{
    public function __construct(
        private readonly WhatsAppPuppeteerService $whatsAppPuppeteerService,
        private readonly MarketingContactNormalizer $contactNormalizer,
    ) {
    }

    public function key(): string
    {
        return 'whatsapp';
    }

    public function canContact(Patient $patient): bool
    {
        return $patient->contactable_whatsapp && $this->resolveTarget($patient) !== null;
    }

    public function resolveTarget(Patient $patient): ?string
    {
        return $this->contactNormalizer->normalizePhone($patient->phone);
    }

    public function send(string $target, string $message, ?string $subject = null, array $context = []): MarketingChannelSendResult
    {
        $normalizedTarget = $this->contactNormalizer->normalizePhone($target) ?? $target;

        return $this->whatsAppPuppeteerService->send(
            $normalizedTarget,
            $message,
            $subject,
            [
                'media_path' => $context['media_path'] ?? null,
                'media_base64' => $context['media_base64'] ?? null,
                'media_name' => $context['media_name'] ?? null,
                'media_mime_type' => $context['media_mime_type'] ?? null,
            ],
        );
    }
}
