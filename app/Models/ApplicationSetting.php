<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationSetting extends Model
{
    use HasFactory;

    protected $table = 'application_settings';

    protected $fillable = [
        'reminder_email',
        'quick_percentages',
        'general_preferences',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quick_percentages' => 'array',
            'general_preferences' => 'array',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
