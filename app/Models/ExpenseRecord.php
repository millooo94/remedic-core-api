<?php

namespace App\Models;

use App\Enums\ExpenseNature;
use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_category_id',
        'expense_template_id',
        'source_performance_record_id',
        'source',
        'generation_key',
        'expense_date',
        'competence_start_date',
        'competence_end_date',
        'competence_months_count',
        'competence_month',
        'competence_year',
        'description',
        'type',
        'nature',
        'amount',
        'supplier',
        'payment_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'competence_start_date' => 'date',
            'competence_end_date' => 'date',
            'competence_months_count' => 'integer',
            'source_performance_record_id' => 'integer',
            'competence_month' => 'integer',
            'competence_year' => 'integer',
            'type' => ExpenseType::class,
            'nature' => ExpenseNature::class,
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

    public function performanceRecord(): BelongsTo
    {
        return $this->belongsTo(PerformanceRecord::class, 'source_performance_record_id');
    }

    public function competenceAllocations(): HasMany
    {
        return $this->hasMany(ExpenseRecordCompetence::class, 'expense_record_id')
            ->orderBy('competence_year')
            ->orderBy('competence_month');
    }
}
