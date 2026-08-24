<?php

namespace App\Models;

use App\Enums\ScientificContributionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalProfileScientificActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_public_profile_id',
        'contribution_type',
        'occurred_on',
        'year',
        'title',
        'source',
        'short_description',
        'url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'contribution_type' => ScientificContributionType::class,
            'occurred_on' => 'date',
            'year' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalPublicProfile::class, 'professional_public_profile_id');
    }
}
