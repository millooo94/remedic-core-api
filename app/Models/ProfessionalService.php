<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalService extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_backend_id',
        'professional_id',
        'service_id',
        'duration_minutes',
        'price_amount',
        'is_visible_public',
        'is_bookable_online',
        'is_featured',
        'source_platform',
        'source_notes',
        'editorial_notes',
        'is_active',
        'public_sort_order',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'duration_minutes' => 'integer',
            'price_amount' => 'decimal:2',
            'is_visible_public' => 'boolean',
            'is_bookable_online' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'public_sort_order' => 'integer',
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
