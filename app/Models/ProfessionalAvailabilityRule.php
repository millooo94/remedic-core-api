<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalAvailabilityRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_id',
        'source',
        'weekday',
        'start_time',
        'end_time',
        'valid_from',
        'valid_until',
        'is_active',
        'notes',
        'external_hash',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
