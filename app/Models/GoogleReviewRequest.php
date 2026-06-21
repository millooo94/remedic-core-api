<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleReviewRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'performance_record_id',
        'professional_id',
        'specialization_id',
        'patient_name',
        'patient_phone',
        'professional_name',
        'professional_title',
        'specialization_name',
        'review_url',
        'message_body',
        'status',
        'scheduled_at',
        'sent_at',
        'excluded_at',
        'excluded_by',
        'error_message',
        'provider_status',
        'provider_message_id',
        'provider_response',
        'template_payload',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'excluded_at' => 'datetime',
            'provider_response' => 'array',
            'template_payload' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function performanceRecord(): BelongsTo
    {
        return $this->belongsTo(PerformanceRecord::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function excludedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }
}
