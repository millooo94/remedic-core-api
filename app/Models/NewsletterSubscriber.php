<?php

namespace App\Models;

use App\Enums\NewsletterSubscriberStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterSubscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'email',
        'status',
        'consent_version',
        'consent_requested_at',
        'confirmation_token_hash',
        'confirmation_expires_at',
        'confirmation_sent_at',
        'confirmed_at',
        'unsubscribed_at',
    ];

    protected $hidden = [
        'confirmation_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => NewsletterSubscriberStatus::class,
            'consent_requested_at' => 'datetime',
            'confirmation_expires_at' => 'datetime',
            'confirmation_sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function consentEvents(): HasMany
    {
        return $this->hasMany(NewsletterConsentEvent::class)->orderBy('occurred_at');
    }

    public function campaignDeliveries(): HasMany
    {
        return $this->hasMany(NewsletterCampaignDelivery::class);
    }
}
