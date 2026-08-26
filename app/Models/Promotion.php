<?php

namespace App\Models;

use App\Enums\PromotionValidityBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'service_id', 'checkup_id', 'promotional_price', 'start_at', 'end_at', 'validity_basis', 'is_active', 'internal_notes'];

    protected function casts(): array
    {
        return ['promotional_price' => 'decimal:2', 'start_at' => 'datetime', 'end_at' => 'datetime', 'validity_basis' => PromotionValidityBasis::class, 'is_active' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function checkup(): BelongsTo
    {
        return $this->belongsTo(Checkup::class)->withTrashed();
    }

    public function targetType(): string
    {
        return $this->service_id !== null ? 'service' : 'checkup';
    }

    public function lifecycleStatus(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->start_at->isFuture()) {
            return 'scheduled';
        }
        if ($this->end_at->lte(now())) {
            return 'expired';
        }

        return 'active';
    }

    public function targetIsOperational(): bool
    {
        if ($this->service_id !== null) {
            return $this->service !== null && ! $this->service->trashed() && (bool) $this->service->is_active;
        }

        return $this->checkup !== null && $this->checkup->isOperationallyAvailable();
    }
}
