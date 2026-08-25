<?php

namespace App\Models;

use App\Enums\JobApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplication extends Model
{
    protected $fillable = ['application_type_id', 'application_type_name_snapshot', 'first_name', 'last_name', 'email', 'phone', 'message', 'cv_path', 'cv_original_name', 'status', 'submitted_at'];

    protected function casts(): array
    {
        return ['status' => JobApplicationStatus::class, 'submitted_at' => 'datetime'];
    }

    public function applicationType(): BelongsTo
    {
        return $this->belongsTo(ApplicationType::class);
    }
}
