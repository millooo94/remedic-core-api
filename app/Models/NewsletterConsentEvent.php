<?php

namespace App\Models;

use App\Enums\NewsletterConsentEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterConsentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'newsletter_subscriber_id',
        'event_type',
        'consent_version',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => NewsletterConsentEventType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(NewsletterSubscriber::class, 'newsletter_subscriber_id');
    }
}
