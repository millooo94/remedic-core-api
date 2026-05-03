<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'segment_type',
        'filters',
        'last_preview_count',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'last_preview_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function manualRecipients(): HasMany
    {
        return $this->hasMany(MarketingSegmentManualRecipient::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MarketingCampaign::class)->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
