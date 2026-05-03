<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMarketingCampaignJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $campaignId,
    ) {
        $this->onQueue('marketing');
    }

    public function handle(MarketingCampaignService $service): void
    {
        $campaign = MarketingCampaign::query()->find($this->campaignId);
        if (! $campaign) {
            return;
        }

        $service->processCampaign($campaign);
    }
}
