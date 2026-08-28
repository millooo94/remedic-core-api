<?php

namespace App\Models;

use App\Enums\ConsentCategoryKey;
use App\Models\Concerns\HasActiveScope;
use App\Models\Concerns\HasContentTranslations;
use App\Models\Concerns\HasOrderedScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsentCategory extends Model
{
    use HasActiveScope;
    use HasContentTranslations;
    use HasFactory;
    use HasOrderedScope;

    protected $fillable = [
        'key',
        'name',
        'description',
        'default_state',
        'is_required',
        'is_active',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if ($category->key === ConsentCategoryKey::NECESSARY->value) {
                $category->default_state = true;
                $category->is_required = true;
                $category->is_active = true;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'default_state' => 'boolean',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(ConsentService::class, 'consent_category_id')->ordered();
    }

    public function requiresConsent(): bool
    {
        return ! (bool) $this->is_required;
    }
}
