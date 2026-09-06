<?php

namespace App\Models;

use App\Enums\NewsletterCampaignDeliveryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterCampaignDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'newsletter_campaign_id', 'newsletter_subscriber_id', 'email_snapshot', 'delivery_status',
        'error_message', 'queued_at', 'sent_at', 'failed_at', 'suppressed_at',
    ];

    protected function casts(): array
    {
        return [
            'delivery_status' => NewsletterCampaignDeliveryStatus::class,
            'queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'suppressed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NewsletterCampaign::class, 'newsletter_campaign_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(NewsletterSubscriber::class, 'newsletter_subscriber_id');
    }
}
