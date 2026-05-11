<?php

namespace App\Models;

use App\Enums\ConsentCategoryKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ConsentRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'consent_uuid',
        'consent_policy_version_id',
        'locale',
        'source',
        'necessary',
        'preferences',
        'analytics',
        'marketing',
        'consented_at',
        'withdrawn_at',
        'rejected_at',
        'user_agent',
        'ip_hash',
        'consent_version_snapshot',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            if (! filled($record->consent_uuid)) {
                $record->consent_uuid = (string) Str::uuid();
            }

            $record->necessary = true;
        });
    }

    protected function casts(): array
    {
        return [
            'necessary' => 'boolean',
            'preferences' => 'boolean',
            'analytics' => 'boolean',
            'marketing' => 'boolean',
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'rejected_at' => 'datetime',
            'consent_version_snapshot' => 'array',
        ];
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(ConsentPolicyVersion::class, 'consent_policy_version_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ConsentPreferenceChange::class, 'consent_record_id')->latest('id');
    }

    /**
     * @return array<string, bool>
     */
    public function categoryPreferences(): array
    {
        return [
            ConsentCategoryKey::NECESSARY->value => true,
            ConsentCategoryKey::PREFERENCES->value => (bool) $this->preferences,
            ConsentCategoryKey::ANALYTICS->value => (bool) $this->analytics,
            ConsentCategoryKey::MARKETING->value => (bool) $this->marketing,
        ];
    }

    public function status(): string
    {
        if ($this->withdrawn_at !== null) {
            return 'withdrawn';
        }

        if ($this->rejected_at !== null && ! $this->preferences && ! $this->analytics && ! $this->marketing) {
            return 'rejected_all';
        }

        if ($this->preferences && $this->analytics && $this->marketing) {
            return 'accepted_all';
        }

        return 'customized';
    }
}
