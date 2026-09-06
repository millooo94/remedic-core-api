<?php

namespace App\Models;

use App\Enums\ConventionPartnerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConventionPartner extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'logo_path', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'type' => ConventionPartnerType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublicOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    public function webProfile(): HasOne
    {
        return $this->hasOne(ConventionPartnerWebProfile::class);
    }
}
