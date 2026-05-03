<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'movement_date' => optional($this->movement_date)->toDateString(),
            'movement_type' => $this->movement_type?->value ?? $this->movement_type,
            'cash_box_type' => $this->cash_box_type?->value ?? $this->cash_box_type,
            'counterparty_name' => $this->counterparty_name,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'source_performance_record_id' => $this->source_performance_record_id,
            'is_auto_generated' => false,
            'source_label' => null,
            'balance_after' => $this->balance_after,
            'created_at' => optional($this->created_at)?->toISOString(),
            'updated_at' => optional($this->updated_at)?->toISOString(),
        ];
    }
}
