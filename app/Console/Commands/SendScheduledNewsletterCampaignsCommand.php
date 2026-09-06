<?php

namespace App\Console\Commands;

use App\Services\NewsletterCampaignService;
use Illuminate\Console\Command;

class SendScheduledNewsletterCampaignsCommand extends Command
{
    protected $signature = 'newsletter:send-scheduled-campaigns';

    protected $description = 'Accoda le campagne newsletter programmate arrivate all’orario di invio';

    public function handle(NewsletterCampaignService $service): int
    {
        $count = $service->dispatchScheduledCampaigns();
        $this->info(sprintf('Campagne newsletter schedulate accodate: %d.', $count));

        return self::SUCCESS;
    }
}
