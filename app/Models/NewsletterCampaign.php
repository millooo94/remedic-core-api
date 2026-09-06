<?php

namespace App\Models;

use App\Enums\NewsletterCampaignStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'internal_name', 'subject', 'preheader', 'content', 'status', 'scheduled_at',
        'sending_started_at', 'sent_at', 'recipient_count', 'sent_count', 'failed_count',
        'suppressed_count', 'last_test_sent_at', 'created_by', 'updated_by', 'launched_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => NewsletterCampaignStatus::class,
            'scheduled_at' => 'datetime', 'sending_started_at' => 'datetime', 'sent_at' => 'datetime',
            'last_test_sent_at' => 'datetime', 'recipient_count' => 'integer', 'sent_count' => 'integer',
            'failed_count' => 'integer', 'suppressed_count' => 'integer',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NewsletterCampaignDelivery::class)->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function launcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launched_by');
    }
}
