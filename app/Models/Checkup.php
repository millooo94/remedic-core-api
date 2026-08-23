<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Checkup extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'display_name',
        'price_amount',
        'indicative_duration_minutes',
        'is_active',
        'organizational_notes',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'indicative_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CheckupService::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'checkup_services')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->withTimestamps();
    }
}
