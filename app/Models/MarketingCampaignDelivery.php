<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketing_campaign_id',
        'patient_id',
        'channel',
        'is_test',
        'target_name',
        'target_value',
        'delivery_status',
        'provider_message_id',
        'provider_status',
        'error_message',
        'provider_response',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'provider_response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
