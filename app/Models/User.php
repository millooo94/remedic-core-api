<?php

namespace App\Models;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    protected static ?bool $backofficeRoleTablesAvailable = null;

    protected static ?string $backofficeRoleTablesSignature = null;

    protected $fillable = [
        'legacy_backend_id',
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
            'legacy_backend_id' => 'integer',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        if ($this->role === UserRole::Admin) {
            return true;
        }

        if (! $this->supportsBackofficeRoles()) {
            return false;
        }

        return $this->hasAnyRole([
            AdminRole::SUPER_ADMIN->value,
            AdminRole::ADMIN->value,
        ]);
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

    public function canAccessBackoffice(): bool
    {
        if (! $this->canAccessPrivateDashboard()) {
            return false;
        }

        if (! $this->supportsBackofficeRoles()) {
            return $this->isAdmin();
        }

        return $this->can(AdminPermission::VIEW_BACKOFFICE->value) || $this->isAdmin();
    }

    public function supportsBackofficeRolesAndPermissions(): bool
    {
        return $this->supportsBackofficeRoles();
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

    protected function supportsBackofficeRoles(): bool
    {
        $tableNames = config('permission.table_names', []);
        $rolesTable = $tableNames['roles'] ?? 'roles';
        $modelHasRolesTable = $tableNames['model_has_roles'] ?? 'model_has_roles';
        $signature = $rolesTable.'|'.$modelHasRolesTable;

        if (self::$backofficeRoleTablesSignature === $signature && self::$backofficeRoleTablesAvailable !== null) {
            return self::$backofficeRoleTablesAvailable;
        }

        self::$backofficeRoleTablesSignature = $signature;
        self::$backofficeRoleTablesAvailable = Schema::hasTable($rolesTable)
            && Schema::hasTable($modelHasRolesTable);

        return self::$backofficeRoleTablesAvailable;
    }
}
