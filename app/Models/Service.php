<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'canonical_name',
        'display_name',
        'importo_prestazione',
        'slug',
        'description',
        'default_duration_minutes',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'importo_prestazione' => 'decimal:2',
            'default_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ServiceAlias::class);
    }

    public function professionalServices(): HasMany
    {
        return $this->hasMany(ProfessionalService::class);
    }
}
