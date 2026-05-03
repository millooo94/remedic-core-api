<?php

namespace App\Services\Marketing\Channels;

use Illuminate\Support\Carbon;

final class MarketingChannelSendResult
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public readonly string $deliveryStatus,
        public readonly ?string $providerStatus = null,
        public readonly ?string $messageId = null,
        public readonly ?string $errorMessage = null,
        public readonly ?array $response = null,
        public readonly ?Carbon $sentAt = null,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public static function sent(?string $messageId = null, ?string $providerStatus = 'sent', ?array $response = null, ?Carbon $sentAt = null): self
    {
        return new self(
            deliveryStatus: 'sent',
            providerStatus: $providerStatus,
            messageId: $messageId,
            response: $response,
            sentAt: $sentAt ?? now(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public static function excluded(?string $providerStatus, ?string $errorMessage, ?array $response = null): self
    {
        return new self(
            deliveryStatus: 'excluded',
            providerStatus: $providerStatus,
            errorMessage: $errorMessage,
            response: $response,
        );
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public static function failed(?string $providerStatus, ?string $errorMessage, ?array $response = null): self
    {
        return new self(
            deliveryStatus: 'failed',
            providerStatus: $providerStatus,
            errorMessage: $errorMessage,
            response: $response,
        );
    }

    public function isSent(): bool
    {
        return $this->deliveryStatus === 'sent';
    }
}
