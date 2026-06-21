<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $avatarUrl = PublicMediaUrl::fromPublicDisk($this->avatar_path, $request);
        $supportsBackofficeRoles = method_exists($this->resource, 'supportsBackofficeRolesAndPermissions')
            && $this->resource->supportsBackofficeRolesAndPermissions();
        $roles = $supportsBackofficeRoles ? $this->getRoleNames()->values()->all() : [];
        $permissions = $supportsBackofficeRoles
            ? $this->getAllPermissions()->pluck('name')->values()->all()
            : [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role?->value,
            'is_active' => $this->is_active,
            'approval_requested_at' => optional($this->approval_requested_at)->toIso8601String(),
            'admin_approved_at' => optional($this->admin_approved_at)->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'rejected_at' => optional($this->rejected_at)->toIso8601String(),
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'is_email_verified' => $this->hasVerifiedEmail(),
            'is_admin_approved' => $this->hasAdminApproval(),
            'can_access_dashboard' => $this->canAccessPrivateDashboard(),
            'can_access_backoffice' => $this->canAccessBackoffice(),
            'backoffice_roles' => $roles,
            'backoffice_permissions' => $permissions,
            'avatar_url' => $avatarUrl,
            'last_login_at' => optional($this->last_login_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
