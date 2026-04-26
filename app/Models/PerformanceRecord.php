<?php

namespace App\Models;

use App\Enums\CalculationMode;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PerformanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'performed_at',
        'professional_id',
        'professional_name_snapshot',
        'category_name_snapshot',
        'service_id',
        'service_name_snapshot',
        'quantity',
        'unit_amount',
        'total_amount',
        'calculation_mode',
        'percentage_value',
        'fixed_amount',
        'professional_amount',
        'center_amount',
        'payment_method',
        'is_invoiced',
        'is_black',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'date',
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'percentage_value' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
            'professional_amount' => 'decimal:2',
            'center_amount' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'is_invoiced' => 'boolean',
            'is_black' => 'boolean',
            'calculation_mode' => CalculationMode::class,
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function cashMovement(): HasOne
    {
        return $this->hasOne(CashMovement::class, 'source_performance_record_id');
    }

    public function linkedExpenseRecord(): HasOne
    {
        return $this->hasOne(ExpenseRecord::class, 'source_performance_record_id');
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
