<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackofficeUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $supportsBackofficeRoles = method_exists($this->resource, 'supportsBackofficeRolesAndPermissions')
            && $this->resource->supportsBackofficeRolesAndPermissions();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'avatar_path' => $this->avatar_path,
            'is_active' => (bool) $this->is_active,
            'email_verified_at' => optional($this->email_verified_at)?->toIso8601String(),
            'approval_requested_at' => optional($this->approval_requested_at)?->toIso8601String(),
            'admin_approved_at' => optional($this->admin_approved_at)?->toIso8601String(),
            'rejected_at' => optional($this->rejected_at)?->toIso8601String(),
            'last_login_at' => optional($this->last_login_at)?->toIso8601String(),
            'can_access_backoffice' => $this->canAccessBackoffice(),
            'roles' => $supportsBackofficeRoles ? $this->getRoleNames()->values()->all() : [],
            'permissions' => $supportsBackofficeRoles ? $this->getAllPermissions()->pluck('name')->values()->all() : [],
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
