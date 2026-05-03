<?php

namespace App\Services\Marketing\Channels;

use App\Models\Patient;

interface MarketingChannel
{
    public function key(): string;

    public function canContact(Patient $patient): bool;

    public function resolveTarget(Patient $patient): ?string;

    /**
     * @param  array<string, mixed>  $context
     */
    public function send(string $target, string $message, ?string $subject = null, array $context = []): MarketingChannelSendResult;
}
