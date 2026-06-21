<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalAvailabilityException extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_id',
        'source',
        'date',
        'type',
        'start_time',
        'end_time',
        'reason',
        'external_hash',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'last_synced_at' => 'datetime',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
