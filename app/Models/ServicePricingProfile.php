<?php

namespace App\Models;

use App\Models\Concerns\HasOrderedScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServicePricingProfile extends Model
{
    use HasFactory;
    use HasOrderedScope;

    protected $fillable = ['service_id', 'label', 'image_path', 'is_ungrouped', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_ungrouped' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServicePricingItem::class)->ordered();
    }

    public function presentation(): HasOne
    {
        return $this->hasOne(ServicePricingProfilePresentation::class);
    }
}
