<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'marketing_segment_id',
        'channel',
        'template_key',
        'subject',
        'message',
        'whatsapp_image_path',
        'whatsapp_image_original_name',
        'whatsapp_image_mime_type',
        'whatsapp_image_size',
        'status',
        'scheduled_at',
        'dispatched_at',
        'completed_at',
        'recipients_count',
        'sent_count',
        'failed_count',
        'excluded_count',
        'last_test_sent_at',
        'created_by',
        'updated_by',
        'launched_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_test_sent_at' => 'datetime',
            'recipients_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'excluded_count' => 'integer',
            'whatsapp_image_size' => 'integer',
        ];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(MarketingSegment::class, 'marketing_segment_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MarketingCampaignDelivery::class)->orderByDesc('id');
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
