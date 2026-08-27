<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePricingProfilePresentation extends Model
{
    use HasFactory;

    protected $fillable = ['service_pricing_profile_id', 'public_label', 'intro', 'is_web_enabled'];

    protected function casts(): array
    {
        return ['is_web_enabled' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ServicePricingProfile::class, 'service_pricing_profile_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ServicePricingPresentationTranslation::class, 'service_pricing_profile_presentation_id');
    }
}
