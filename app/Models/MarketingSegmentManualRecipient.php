<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingSegmentManualRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'marketing_segment_id',
        'patient_id',
        'original_value',
        'normalized_phone',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(MarketingSegment::class, 'marketing_segment_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
