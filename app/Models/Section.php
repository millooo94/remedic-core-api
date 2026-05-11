<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Section extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_backend_id',
        'sectionable_id',
        'sectionable_type',
        'key',
        'title',
        'subtitle',
        'content',
        'extra_json',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'extra_json' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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

    public function sectionable(): MorphTo
    {
        return $this->morphTo();
    }
}
