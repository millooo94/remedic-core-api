<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'full_name',
        'tax_code',
        'sex',
        'birth_date',
        'year_of_birth',
        'phone',
        'email',
        'residence_address',
        'residence_city',
        'residence_zip',
        'residence_latitude',
        'residence_longitude',
        'geocoding_status',
        'geocoded_at',
        'contactable_sms',
        'contactable_whatsapp',
        'contactable_email',
        'excluded_from_campaigns',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'year_of_birth' => 'integer',
            'residence_latitude' => 'float',
            'residence_longitude' => 'float',
            'geocoded_at' => 'datetime',
            'contactable_sms' => 'boolean',
            'contactable_whatsapp' => 'boolean',
            'contactable_email' => 'boolean',
            'excluded_from_campaigns' => 'boolean',
        ];
    }

    public function performanceRecords(): BelongsToMany
    {
        return $this->belongsToMany(PerformanceRecord::class)
            ->withPivot('sort_order')
            ->orderByDesc('performed_at')
            ->orderByDesc('performance_records.id');
    }

    public function marketingDeliveries(): HasMany
    {
        return $this->hasMany(MarketingCampaignDelivery::class)->orderByDesc('id');
    }

    public function googleReviewRequests(): HasMany
    {
        return $this->hasMany(GoogleReviewRequest::class)->orderByDesc('id');
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
