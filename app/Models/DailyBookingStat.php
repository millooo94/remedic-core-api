<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBookingStat extends Model
{
    protected $fillable = ['date', 'bookings_count', 'cancellations_count', 'submitted_by', 'submitted_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'submitted_at' => 'datetime', 'bookings_count' => 'integer', 'cancellations_count' => 'integer'];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
