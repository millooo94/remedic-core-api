<?php

namespace App\Models;

use App\Models\Concerns\HasActiveScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentPolicyVersion extends Model
{
    use HasActiveScope;
    use HasFactory;

    protected $fillable = [
        'version',
        'banner_title',
        'banner_text',
        'preferences_title',
        'preferences_text',
        'policy_page_id',
        'cookie_policy_page_id',
        'privacy_policy_page_id',
        'is_active',
        'published_at',
        'requires_reconsent',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $version): void {
            if (! $version->is_active) {
                return;
            }

            static::query()
                ->whereKeyNot($version->getKey())
                ->update(['is_active' => false]);
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_reconsent' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->whereNull('published_at')
                ->orWhere('published_at', '<=', now());
        });
    }

    public function policyPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'policy_page_id');
    }

    public function cookiePolicyPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'cookie_policy_page_id');
    }

    public function privacyPolicyPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'privacy_policy_page_id');
    }
}
