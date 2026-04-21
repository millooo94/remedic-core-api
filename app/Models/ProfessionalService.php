<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalService extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_id',
        'service_id',
        'duration_minutes',
        'price_amount',
        'is_visible_public',
        'is_bookable_online',
        'source_platform',
        'source_notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price_amount' => 'decimal:2',
            'is_visible_public' => 'boolean',
            'is_bookable_online' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
