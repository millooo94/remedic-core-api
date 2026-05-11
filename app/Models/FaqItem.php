<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FaqItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_backend_id',
        'faqable_id',
        'faqable_type',
        'question',
        'answer',
        'sort_order',
        'is_active',
        'is_structured_data',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_structured_data' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function faqable(): MorphTo
    {
        return $this->morphTo();
    }
}
