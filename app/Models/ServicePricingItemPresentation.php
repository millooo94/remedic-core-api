<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePricingItemPresentation extends Model
{
    use HasFactory;

    protected $fillable = ['service_pricing_item_id', 'icon_path', 'public_label', 'public_note', 'is_highlighted', 'is_web_enabled'];

    protected function casts(): array
    {
        return ['is_highlighted' => 'boolean', 'is_web_enabled' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ServicePricingItem::class, 'service_pricing_item_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ServicePricingPresentationTranslation::class, 'service_pricing_item_presentation_id');
    }
}
