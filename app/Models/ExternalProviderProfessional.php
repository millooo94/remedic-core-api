<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalProviderProfessional extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_id',
        'provider',
        'external_name',
        'external_id',
        'external_url',
        'enabled',
        'last_synced_at',
        'sync_status',
        'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
