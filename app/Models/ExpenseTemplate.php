<?php

namespace App\Models;

use App\Enums\ExpenseRecurrence;
use App\Enums\ExpenseType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'type',
        'recurrence',
        'default_amount',
        'start_date',
        'end_date',
        'day_of_generation',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ExpenseType::class,
            'recurrence' => ExpenseRecurrence::class,
            'default_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'day_of_generation' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }
}
