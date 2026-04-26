<?php

namespace App\Models;

use App\Enums\CashBoxType;
use App\Enums\CashMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_date',
        'cash_box_type',
        'movement_type',
        'counterparty_name',
        'amount',
        'reason',
        'notes',
        'source_performance_record_id',
        'balance_after',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'cash_box_type' => CashBoxType::class,
            'movement_type' => CashMovementType::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'source_performance_record_id' => 'integer',
        ];
    }

    public function performanceRecord(): BelongsTo
    {
        return $this->belongsTo(PerformanceRecord::class, 'source_performance_record_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
