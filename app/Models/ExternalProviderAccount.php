<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalProviderAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'label',
        'enabled',
        'username_encrypted',
        'password_encrypted',
        'storage_state_path',
        'login_status',
        'last_login_at',
        'last_session_verified_at',
        'last_error',
        'last_availability_sync_at',
        'last_patient_sync_at',
        'last_appointment_sync_at',
        'last_test_at',
        'notes',
        'config_json',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'username_encrypted' => 'encrypted',
            'password_encrypted' => 'encrypted',
            'last_login_at' => 'datetime',
            'last_session_verified_at' => 'datetime',
            'last_availability_sync_at' => 'datetime',
            'last_patient_sync_at' => 'datetime',
            'last_appointment_sync_at' => 'datetime',
            'last_test_at' => 'datetime',
            'config_json' => 'array',
        ];
    }
}
