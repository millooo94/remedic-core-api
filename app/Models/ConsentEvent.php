<?php

namespace App\Models;

use App\Enums\ConsentEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentEvent extends Model
{
    use HasFactory;

    protected $table = 'consent_preference_changes';

    protected $fillable = [
        'consent_record_id',
        'event_type',
        'configuration_version',
        'necessary',
        'preferences',
        'statistics',
        'marketing',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ConsentEventType::class,
            'configuration_version' => 'integer',
            'necessary' => 'boolean',
            'preferences' => 'boolean',
            'statistics' => 'boolean',
            'marketing' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function consentRecord(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class);
    }
}
