<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasContentTranslations;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfessionalPublicProfile extends Model
{
    use HasContentTranslations;
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $fillable = [
        'legacy_backend_id',
        'professional_id',
        'slug',
        'title_prefix',
        'short_bio',
        'bio_content',
        'approach_content',
        'registration_number',
        'birth_date',
        'birth_place',
        'profile_image_path',
        'seo_title',
        'seo_description',
        'seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'is_active',
        'is_web_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'birth_date' => 'date',
            'robots' => RobotsValue::class,
            'is_active' => 'boolean',
            'is_web_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function approachPrinciples(): HasMany
    {
        return $this->hasMany(ProfessionalProfileApproachPrinciple::class)->orderBy('sort_order')->orderBy('id');
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(ProfessionalProfileCompetency::class)->orderBy('sort_order')->orderBy('id');
    }

    public function heroCompetencies(): BelongsToMany
    {
        return $this->belongsToMany(
            ProfessionalProfileCompetency::class,
            'professional_profile_hero_competencies',
            'professional_public_profile_id',
            'professional_profile_competency_id'
        )->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function scientificActivities(): HasMany
    {
        return $this->hasMany(ProfessionalProfileScientificActivity::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->where('is_web_enabled', true)
            ->whereHas('professional', fn (Builder $master) => $master->effectivelyVisible());
    }

    public function isEffectivelyVisible(): bool
    {
        return (bool) $this->is_web_enabled && (bool) $this->professional?->isEffectivelyVisible();
    }

    protected static function booted(): void
    {
        static::deleting(function (ProfessionalPublicProfile $profile): void {
            $profile->sections()->delete();
            $profile->faqs()->delete();
        });
    }
}
