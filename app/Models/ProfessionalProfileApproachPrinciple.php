<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalProfileApproachPrinciple extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_public_profile_id',
        'label',
        'icon_key',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProfessionalPublicProfile::class, 'professional_public_profile_id');
    }
}
