<?php

namespace App\Models;

use App\Enums\JobApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class JobApplication extends Model
{
    protected $fillable = ['public_id', 'application_type_id', 'application_type_name_snapshot', 'application_type_key_snapshot', 'first_name', 'last_name', 'email', 'phone', 'message', 'locale', 'privacy_consent_at', 'privacy_policy_version', 'cv_path', 'cv_original_name', 'cv_mime_type', 'cv_size_bytes', 'status', 'first_opened_at', 'first_opened_by_user_id', 'submitted_at'];

    protected function casts(): array
    {
        return ['status' => JobApplicationStatus::class, 'submitted_at' => 'datetime', 'privacy_consent_at' => 'datetime', 'first_opened_at' => 'datetime', 'cv_size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $application) => $application->public_id ??= (string) Str::uuid());
    }

    public function applicationType(): BelongsTo
    {
        return $this->belongsTo(ApplicationType::class);
    }

    public function firstOpenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_opened_by_user_id');
    }
}
