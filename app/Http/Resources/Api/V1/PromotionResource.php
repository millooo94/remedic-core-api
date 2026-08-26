<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Checkup;
use App\Models\Promotion;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Promotion */
class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = $this->service_id === null ? null : Service::withTrashed()->find($this->service_id);
        $checkup = $this->checkup_id === null ? null : Checkup::withTrashed()->with('items.service')->find($this->checkup_id);
        $standardPrice = $service?->importo_prestazione ?? $checkup?->price_amount;
        $standardPrice = $standardPrice === null ? null : (float) $standardPrice;
        $promotionalPrice = (float) $this->promotional_price;
        $saving = $standardPrice === null ? null : round($standardPrice - $promotionalPrice, 2);
        $discount = $standardPrice !== null && $standardPrice > 0 ? round(($saving / $standardPrice) * 100, 2) : null;
        $targetOperational = $service !== null
            ? (! $service->trashed() && (bool) $service->is_active)
            : ($checkup?->isOperationallyAvailable() ?? false);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'target_type' => $this->targetType(),
            'target_id' => $this->service_id ?? $this->checkup_id,
            'target_name' => $service?->display_name ?? $checkup?->display_name,
            'target_is_operational' => $targetOperational,
            'standard_price' => $standardPrice,
            'promotional_price' => $this->promotional_price,
            'saving_amount' => $saving,
            'discount_percentage' => $discount,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'validity_basis' => $this->validity_basis?->value,
            'lifecycle_status' => $this->lifecycleStatus(),
            'is_active' => (bool) $this->is_active,
            'is_effectively_available' => ! $this->trashed() && $this->lifecycleStatus() === 'active' && $targetOperational,
            'internal_notes' => $this->internal_notes,
            'is_archived' => $this->trashed(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
