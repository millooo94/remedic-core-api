<?php

namespace App\Models;

use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_category_id',
        'expense_template_id',
        'source',
        'generation_key',
        'expense_date',
        'competence_month',
        'competence_year',
        'description',
        'type',
        'amount',
        'supplier',
        'payment_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'competence_month' => 'integer',
            'competence_year' => 'integer',
            'type' => ExpenseType::class,
            'amount' => 'decimal:2',
            'payment_status' => PaymentStatus::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExpenseTemplate::class, 'expense_template_id');
    }
}
