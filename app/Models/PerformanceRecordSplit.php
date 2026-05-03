<?php

namespace App\Models;

use App\Enums\PerformanceSplitSubjectType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceRecordSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'performance_record_id',
        'subject_type',
        'professional_id',
        'professional_name_snapshot',
        'amount',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => PerformanceSplitSubjectType::class,
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function performanceRecord(): BelongsTo
    {
        return $this->belongsTo(PerformanceRecord::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
