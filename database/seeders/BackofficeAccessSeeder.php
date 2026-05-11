<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BackofficeAccessSeeder extends Seeder
{
    protected const GUARD_NAME = BackofficePermissionSeeder::GUARD_NAME;

    public function run(): void
    {
        $this->call(BackofficePermissionSeeder::class);

        $permissionRegistrar = app(PermissionRegistrar::class);
        $permissionRegistrar->forgetCachedPermissions();
        $permissionRegistrar->clearPermissionsCollection();

        $roles = [];

        foreach (AdminRole::cases() as $role) {
            $roles[$role->value] = Role::query()->firstOrCreate([
                'name' => $role->value,
                'guard_name' => self::GUARD_NAME,
            ]);
        }

        $permissionsByName = Permission::query()
            ->where('guard_name', self::GUARD_NAME)
            ->whereIn('name', AdminPermission::values())
            ->get()
            ->keyBy('name');

        $resolvePermissions = function (array $names) use ($permissionsByName): array {
            return array_map(function (string $name) use ($permissionsByName): Permission {
                $permission = $permissionsByName->get($name);

                if (! $permission instanceof Permission) {
                    throw new \RuntimeException("Missing backoffice permission [{$name}] for guard [".self::GUARD_NAME."].");
                }

                return $permission;
            }, $names);
        };

        $this->syncRolePermissions(
            $roles[AdminRole::SUPER_ADMIN->value],
            $resolvePermissions(AdminPermission::values()),
        );
        $this->syncRolePermissions($roles[AdminRole::ADMIN->value], $resolvePermissions([
            AdminPermission::VIEW_BACKOFFICE->value,
            AdminPermission::MANAGE_PAGES->value,
            AdminPermission::MANAGE_SPECIALIZATIONS->value,
            AdminPermission::MANAGE_SERVICES->value,
            AdminPermission::MANAGE_DOCTORS->value,
            AdminPermission::MANAGE_BLOG_POSTS->value,
            AdminPermission::MANAGE_SETTINGS->value,
            AdminPermission::MANAGE_CONSENT_CONFIGURATION->value,
            AdminPermission::VIEW_CONSENT_RECORDS->value,
            AdminPermission::MANAGE_USERS->value,
            AdminPermission::PUBLISH_CONTENT->value,
            AdminPermission::MANAGE_SEO_FIELDS->value,
        ]));
        $this->syncRolePermissions($roles[AdminRole::EDITOR->value], $resolvePermissions([
            AdminPermission::VIEW_BACKOFFICE->value,
            AdminPermission::MANAGE_PAGES->value,
            AdminPermission::MANAGE_SPECIALIZATIONS->value,
            AdminPermission::MANAGE_SERVICES->value,
            AdminPermission::MANAGE_DOCTORS->value,
            AdminPermission::MANAGE_BLOG_POSTS->value,
        ]));
        $this->syncRolePermissions($roles[AdminRole::SEO_MANAGER->value], $resolvePermissions([
            AdminPermission::VIEW_BACKOFFICE->value,
            AdminPermission::MANAGE_PAGES->value,
            AdminPermission::MANAGE_SPECIALIZATIONS->value,
            AdminPermission::MANAGE_SERVICES->value,
            AdminPermission::MANAGE_DOCTORS->value,
            AdminPermission::MANAGE_BLOG_POSTS->value,
            AdminPermission::MANAGE_REDIRECTS->value,
            AdminPermission::MANAGE_SETTINGS->value,
            AdminPermission::MANAGE_CONSENT_CONFIGURATION->value,
            AdminPermission::PUBLISH_CONTENT->value,
            AdminPermission::MANAGE_SEO_FIELDS->value,
        ]));

        $this->syncExistingAdminUsers($roles);

        $permissionRegistrar->forgetCachedPermissions();
        $permissionRegistrar->clearPermissionsCollection();
    }

    /**
     * @param  array<string, Role>  $roles
     */
    protected function syncExistingAdminUsers(array $roles): void
    {
        $primaryAdminEmail = mb_strtolower(trim((string) config('auth.primary_admin.email')));

        User::query()
            ->where('role', UserRole::Admin)
            ->orWhere('email', $primaryAdminEmail)
            ->get()
            ->each(function (User $user) use ($primaryAdminEmail, $roles): void {
                $userEmail = mb_strtolower(trim((string) $user->email));

                if ($userEmail === $primaryAdminEmail) {
                    $user->syncRoles([$roles[AdminRole::SUPER_ADMIN->value]->name]);

                    return;
                }

                if (! $user->hasAnyRole([
                    AdminRole::SUPER_ADMIN->value,
                    AdminRole::ADMIN->value,
                    AdminRole::EDITOR->value,
                    AdminRole::SEO_MANAGER->value,
                ])) {
                    $user->syncRoles([$roles[AdminRole::ADMIN->value]->name]);
                }
            });
    }

    /**
     * @param  array<int, Permission>  $permissions
     */
    protected function syncRolePermissions(Role $role, array $permissions): void
    {
        $role->permissions()->sync(
            array_map(
                static fn (Permission $permission): int|string => $permission->getKey(),
                $permissions,
            ),
        );

        $role->unsetRelation('permissions');
    }
}
