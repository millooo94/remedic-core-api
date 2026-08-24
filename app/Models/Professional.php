<?php

namespace App\Models;

use App\Enums\ProfessionalGender;
use App\Enums\ProfessionalSubjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Professional extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_backend_id',
        'subject_type',
        'gender',
        'honorific_prefix',
        'first_name',
        'last_name',
        'company_name',
        'full_name',
        'birth_date',
        'birth_place',
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
            'legacy_backend_id' => 'integer',
            'birth_date' => 'date',
            'is_active' => 'boolean',
            'gender' => ProfessionalGender::class,
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

    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(Specialization::class, 'professional_specialization')
            ->withPivot('is_primary', 'sort_order')
            ->orderByPivot('sort_order')
            ->orderBy('specializations.id')
            ->withTimestamps();
    }

    public function webSpecializations(): BelongsToMany
    {
        return $this->specializations();
    }

    public function performanceRecords(): HasMany
    {
        return $this->hasMany(PerformanceRecord::class);
    }

    public function performanceRecordSplits(): HasMany
    {
        return $this->hasMany(PerformanceRecordSplit::class);
    }

    public function degrees(): HasMany
    {
        return $this->hasMany(ProfessionalDegree::class)->orderBy('sort_order')->orderBy('id');
    }

    public function academicSpecializations(): HasMany
    {
        return $this->hasMany(ProfessionalAcademicSpecialization::class)->orderBy('sort_order')->orderBy('id');
    }

    public function boardRegistrations(): HasMany
    {
        return $this->hasMany(ProfessionalBoardRegistration::class)->orderBy('sort_order')->orderBy('id');
    }

    public function careerExperiences(): HasMany
    {
        return $this->hasMany(ProfessionalCareerExperience::class)->orderBy('sort_order')->orderBy('id');
    }

    public function publicProfile(): HasOne
    {
        return $this->hasOne(ProfessionalPublicProfile::class);
    }

    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->where($this->qualifyColumn('is_active'), true)
            ->whereHas('publicProfile', fn (Builder $profile) => $profile->where('is_web_enabled', true));
    }

    public function isEffectivelyVisible(): bool
    {
        return (bool) $this->is_active && (bool) $this->publicProfile?->is_web_enabled;
    }

    public function availabilityRules(): HasMany
    {
        return $this->hasMany(ProfessionalAvailabilityRule::class);
    }

    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(ProfessionalAvailabilityException::class);
    }
}
