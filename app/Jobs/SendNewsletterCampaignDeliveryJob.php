<?php

namespace App\Jobs;

use App\Services\NewsletterCampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;

class SendNewsletterCampaignDeliveryJob implements ShouldBeUnique, ShouldQueue
{
    use FoundationQueueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('marketing');
    }

    public function uniqueId(): string
    {
        return 'newsletter-campaign-delivery-'.$this->deliveryId;
    }

    public function handle(NewsletterCampaignService $service): void
    {
        $service->processDelivery($this->deliveryId);
    }

    public function failed(\Throwable $exception): void
    {
        app(NewsletterCampaignService::class)->markDeliveryFailed($this->deliveryId);
    }
}
