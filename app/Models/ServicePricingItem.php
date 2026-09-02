<?php

namespace App\Models;

use App\Enums\ServicePricingItemKind;
use App\Enums\ServicePricingRecipient;
use App\Models\Concerns\HasOrderedScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServicePricingItem extends Model
{
    use HasFactory;
    use HasOrderedScope;

    protected $fillable = ['service_pricing_profile_id', 'label', 'kind', 'recipient', 'price_amount', 'business_note', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['kind' => ServicePricingItemKind::class, 'recipient' => ServicePricingRecipient::class, 'price_amount' => 'decimal:2', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ServicePricingProfile::class, 'service_pricing_profile_id');
    }

    public function presentation(): HasOne
    {
        return $this->hasOne(ServicePricingItemPresentation::class);
    }
}
