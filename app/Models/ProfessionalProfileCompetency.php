<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProfessionalProfileCompetency extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_public_profile_id',
        'title',
        'description',
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

    public function heroProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            ProfessionalPublicProfile::class,
            'professional_profile_hero_competencies',
            'professional_profile_competency_id',
            'professional_public_profile_id'
        )->withPivot('sort_order');
    }
}
