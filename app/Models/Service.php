<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Enums\ServiceClassification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'legacy_backend_id',
        'category_id',
        'classification',
        'canonical_name',
        'display_name',
        'importo_prestazione',
        'slug',
        'description',
        'short_description',
        'intro_text',
        'local_intro_text',
        'local_area_notes',
        'preparation_notes',
        'duration_text',
        'price_text',
        'exam_report_time',
        'featured_image_path',
        'social_image_path',
        'default_duration_minutes',
        'is_diagnostic',
        'is_visit',
        'is_featured',
        'is_local_seo_enabled',
        'seo_title',
        'local_seo_title',
        'seo_description',
        'local_seo_description',
        'seo_h1',
        'local_seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'is_active',
        'is_web_active',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'importo_prestazione' => 'decimal:2',
            'default_duration_minutes' => 'integer',
            'robots' => RobotsValue::class,
            'classification' => ServiceClassification::class,
            'is_diagnostic' => 'boolean',
            'is_visit' => 'boolean',
            'is_featured' => 'boolean',
            'is_local_seo_enabled' => 'boolean',
            'is_active' => 'boolean',
            'is_web_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function webProfile(): HasOne
    {
        return $this->hasOne(ServiceWebProfile::class);
    }

    public function pricingProfiles(): HasMany
    {
        return $this->hasMany(ServicePricingProfile::class)->ordered();
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ServiceAlias::class);
    }

    public function professionalServices(): HasMany
    {
        return $this->hasMany(ProfessionalService::class);
    }

    public function checkupItems(): HasMany
    {
        return $this->hasMany(CheckupService::class);
    }

    public function checkups(): BelongsToMany
    {
        return $this->belongsToMany(Checkup::class, 'checkup_services')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->withTimestamps();
    }

    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(Specialization::class, 'service_specialization')
            ->withPivot('is_primary', 'sort_order')
            ->withTimestamps();
    }

    public function publicLabel(): string
    {
        $displayName = trim((string) $this->display_name);

        if ($displayName !== '') {
            return $displayName;
        }

        return trim((string) $this->canonical_name);
    }

    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->whereNull($query->getModel()->getQualifiedDeletedAtColumn())
            ->where('is_active', true)
            ->whereHas('webProfile', fn (Builder $profile) => $profile->where('is_web_enabled', true));
    }

    public function isEffectivelyVisible(): bool
    {
        return ! $this->trashed()
            && (bool) $this->is_active
            && (bool) $this->webProfile?->is_web_enabled;
    }
}
