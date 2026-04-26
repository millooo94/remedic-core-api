<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseRecordCompetence extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_record_id',
        'competence_date',
        'competence_month',
        'competence_year',
        'allocated_amount',
    ];

    protected function casts(): array
    {
        return [
            'expense_record_id' => 'integer',
            'competence_date' => 'date',
            'competence_month' => 'integer',
            'competence_year' => 'integer',
            'allocated_amount' => 'decimal:2',
        ];
    }

    public function expenseRecord(): BelongsTo
    {
        return $this->belongsTo(ExpenseRecord::class, 'expense_record_id');
    }
}
