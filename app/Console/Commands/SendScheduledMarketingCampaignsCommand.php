<?php

namespace App\Console\Commands;

use App\Services\MarketingCampaignService;
use Illuminate\Console\Command;

class SendScheduledMarketingCampaignsCommand extends Command
{
    protected $signature = 'marketing:send-scheduled-campaigns';

    protected $description = 'Accoda le campagne marketing schedulate arrivate all orario di invio';

    public function handle(MarketingCampaignService $service): int
    {
        $count = $service->dispatchScheduledCampaigns();

        $this->info(sprintf('Campagne schedulate accodate: %d.', $count));

        return self::SUCCESS;
    }
}
