<?php

namespace App\Models;

use App\Enums\NotificationSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InternalNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'recipient_user_id', 'kind', 'context', 'title', 'message', 'severity',
        'action', 'source_type', 'source_public_id', 'deduplication_key', 'read_at',
    ];

    protected function casts(): array
    {
        return ['action' => 'array', 'severity' => NotificationSeverity::class, 'read_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            if (! filled($notification->public_id)) {
                $notification->public_id = (string) Str::uuid();
            }
        });
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
