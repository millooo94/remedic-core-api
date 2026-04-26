<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'last_name',
        'email',
        'password',
        'role',
        'is_active',
        'approval_requested_at',
        'admin_approved_at',
        'approved_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'avatar_path',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'approval_requested_at' => 'datetime',
            'admin_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'last_login_at' => 'datetime',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isPrimaryAdmin(): bool
    {
        return $this->email === config('auth.primary_admin.email');
    }

    public function hasAdminApproval(): bool
    {
        return $this->admin_approved_at !== null;
    }

    public function canAccessPrivateDashboard(): bool
    {
        return $this->is_active && $this->hasVerifiedEmail() && $this->hasAdminApproval();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rejected_by_user_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim((string) ($this->name.' '.$this->last_name));
    }
}
