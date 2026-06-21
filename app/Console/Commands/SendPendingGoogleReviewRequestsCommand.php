<?php

namespace App\Console\Commands;

use App\Services\GoogleReviewRequestService;
use Illuminate\Console\Command;

class SendPendingGoogleReviewRequestsCommand extends Command
{
    protected $signature = 'google-reviews:send-pending';

    protected $description = 'Invia le richieste recensione Google WhatsApp in attesa e schedulate.';

    public function __construct(
        private readonly GoogleReviewRequestService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sent = $this->service->sendPending();

        $this->info("Richieste recensione inviate: {$sent}");

        return self::SUCCESS;
    }
}
