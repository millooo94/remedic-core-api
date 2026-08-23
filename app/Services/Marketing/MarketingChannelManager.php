<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Channels\EmailChannel;
use App\Services\Marketing\Channels\MarketingChannel;
use App\Services\Marketing\Channels\SmsChannel;
use InvalidArgumentException;

class MarketingChannelManager
{
    /**
     * @var array<string, MarketingChannel>
     */
    private array $channels;

    public function __construct(
        SmsChannel $smsChannel,
        EmailChannel $emailChannel,
    ) {
        $this->channels = [
            $smsChannel->key() => $smsChannel,
            $emailChannel->key() => $emailChannel,
        ];
    }

    public function for(string $key): MarketingChannel
    {
        if (! array_key_exists($key, $this->channels)) {
            throw new InvalidArgumentException(sprintf('Canale marketing non supportato: %s', $key));
        }

        return $this->channels[$key];
    }
}
