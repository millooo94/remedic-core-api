<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'recipient_email',
        'subject',
        'body',
        'frequency',
        'day_of_month',
        'day_of_week',
        'is_active',
        'notes',
        'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'day_of_month' => 'integer',
            'day_of_week' => 'integer',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }
}

