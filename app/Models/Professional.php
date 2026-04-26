<?php

namespace App\Models;

use App\Enums\ProfessionalSubjectType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Professional extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_type',
        'first_name',
        'last_name',
        'company_name',
        'full_name',
        'area_name',
        'email',
        'iban',
        'avatar_path',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'subject_type' => ProfessionalSubjectType::class,
        ];
    }

    public function professionalServices(): HasMany
    {
        return $this->hasMany(ProfessionalService::class);
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'professional_service_categories', 'professional_id', 'service_category_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->orderBy('professional_service_categories.id')
            ->withTimestamps();
    }

    public function performanceRecords(): HasMany
    {
        return $this->hasMany(PerformanceRecord::class);
    }
}
