<?php

namespace App\Models;

use App\Enums\ConsentExecutionMode;
use App\Models\Concerns\HasActiveScope;
use App\Models\Concerns\HasOrderedScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentService extends Model
{
    use HasActiveScope;
    use HasFactory;
    use HasOrderedScope;

    protected $fillable = [
        'consent_category_id',
        'key',
        'name',
        'provider',
        'description',
        'purpose',
        'privacy_url',
        'cookie_names',
        'retention_period',
        'legal_basis_hint',
        'execution_mode',
        'public_config',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cookie_names' => 'array',
            'public_config' => 'array',
            'execution_mode' => ConsentExecutionMode::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ConsentCategory::class, 'consent_category_id');
    }
}
