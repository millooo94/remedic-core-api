<?php

namespace App\Models;

use App\Enums\ConsentEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentPreferenceChange extends Model
{
    use HasFactory;

    protected $fillable = [
        'consent_record_id',
        'event_type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ConsentEventType::class,
            'payload' => 'array',
        ];
    }

    public function consentRecord(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class, 'consent_record_id');
    }
}
