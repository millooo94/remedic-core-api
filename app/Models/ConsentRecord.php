<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ConsentRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'management_token_hash',
        'configuration_version',
        'necessary',
        'preferences',
        'statistics',
        'marketing',
        'consented_at',
        'last_updated_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            if (! filled($record->consent_uuid)) {
                $record->consent_uuid = (string) Str::uuid();
            }

            if (! filled($record->public_id)) {
                $record->public_id = (string) Str::uuid();
            }

            $record->necessary = true;
        });
    }

    protected function casts(): array
    {
        return [
            'necessary' => 'boolean',
            'preferences' => 'boolean',
            'statistics' => 'boolean',
            'marketing' => 'boolean',
            'consented_at' => 'datetime',
            'last_updated_at' => 'datetime',
            'configuration_version' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConsentEvent::class)->orderBy('occurred_at');
    }
}
