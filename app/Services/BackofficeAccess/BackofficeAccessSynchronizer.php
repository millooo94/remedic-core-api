<?php

namespace App\Services\BackofficeAccess;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class BackofficeAccessSynchronizer
{
    public function __construct(
        private readonly BackofficeAccessCatalog $catalog,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {}

    public function tablesAreAvailable(): bool
    {
        $tableNames = config('permission.table_names', []);

        foreach (['permissions', 'roles', 'role_has_permissions', 'model_has_roles'] as $key) {
            $tableName = $tableNames[$key] ?? null;

            if (! is_string($tableName) || ! Schema::hasTable($tableName)) {
                return false;
            }
        }

        return Schema::hasTable((new User)->getTable());
    }

    public function isSynchronized(): bool
    {
        return $this->tablesAreAvailable() && $this->databaseMatchesCatalog();
    }

    private function databaseMatchesCatalog(): bool
    {

        $expectedPermissions = $this->sorted($this->catalog->permissions());
        $actualPermissions = Permission::query()
            ->where('guard_name', BackofficeAccessCatalog::GUARD_NAME)
            ->whereIn('name', $expectedPermissions)
            ->pluck('name')
            ->all();

        if ($this->sorted($actualPermissions) !== $expectedPermissions) {
            return false;
        }

        $roles = Role::query()
            ->with('permissions')
            ->where('guard_name', BackofficeAccessCatalog::GUARD_NAME)
            ->whereIn('name', $this->catalog->roles())
            ->get()
            ->keyBy('name');

        if ($roles->count() !== count($this->catalog->roles())) {
            return false;
        }

        foreach ($this->catalog->rolePermissions() as $roleName => $permissionNames) {
            $role = $roles->get($roleName);

            if (! $role instanceof Role
                || $this->sorted($role->permissions->pluck('name')->all()) !== $this->sorted($permissionNames)) {
                return false;
            }
        }

        $primaryAdmin = $this->primaryAdmin();

        if ($primaryAdmin instanceof User
            && $this->sorted($primaryAdmin->getRoleNames()->all()) !== [AdminRole::SUPER_ADMIN->value]) {
            return false;
        }

        return ! $this->legacyAdminsWithoutRolesQuery()->exists();
    }

    /**
     * @return array{changed: bool, permissions: int, roles: int, role_permissions: int, primary_admin_assigned: int, legacy_admins_assigned: int}
     */
    public function synchronize(): array
    {
        if (! $this->tablesAreAvailable()) {
            throw new RuntimeException('Backoffice RBAC tables are not available. Run the database migrations before synchronization.');
        }

        $wasSynchronized = $this->databaseMatchesCatalog();
        $this->clearPermissionCache();

        try {
            $result = DB::transaction(function (): array {
                $now = now();

                Permission::query()->insertOrIgnore(
                    array_map(fn (string $name): array => [
                        'name' => $name,
                        'guard_name' => BackofficeAccessCatalog::GUARD_NAME,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $this->catalog->permissions()),
                );

                Role::query()->insertOrIgnore(
                    array_map(fn (string $name): array => [
                        'name' => $name,
                        'guard_name' => BackofficeAccessCatalog::GUARD_NAME,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $this->catalog->roles()),
                );

                $permissions = Permission::query()
                    ->where('guard_name', BackofficeAccessCatalog::GUARD_NAME)
                    ->whereIn('name', $this->catalog->permissions())
                    ->get()
                    ->keyBy('name');
                $roles = Role::query()
                    ->where('guard_name', BackofficeAccessCatalog::GUARD_NAME)
                    ->whereIn('name', $this->catalog->roles())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('name');

                $rolePermissionCount = 0;

                foreach ($this->catalog->rolePermissions() as $roleName => $permissionNames) {
                    $role = $roles->get($roleName);

                    if (! $role instanceof Role) {
                        throw new RuntimeException("Canonical backoffice role [{$roleName}] could not be materialized.");
                    }

                    $permissionIds = array_map(function (string $permissionName) use ($permissions): int|string {
                        $permission = $permissions->get($permissionName);

                        if (! $permission instanceof Permission) {
                            throw new RuntimeException("Canonical backoffice permission [{$permissionName}] could not be materialized.");
                        }

                        return $permission->getKey();
                    }, $permissionNames);

                    $role->permissions()->sync($permissionIds);
                    $role->unsetRelation('permissions');
                    $rolePermissionCount += count($permissionIds);
                }

                $primaryAssigned = 0;
                $primaryAdmin = $this->primaryAdmin();

                if ($primaryAdmin instanceof User
                    && $this->sorted($primaryAdmin->getRoleNames()->all()) !== [AdminRole::SUPER_ADMIN->value]) {
                    $primaryAdmin->syncRoles([AdminRole::SUPER_ADMIN->value]);
                    $primaryAssigned = 1;
                }

                $legacyAssigned = 0;
                $this->legacyAdminsWithoutRolesQuery()
                    ->orderBy('id')
                    ->chunkById(100, function ($users) use (&$legacyAssigned): void {
                        foreach ($users as $user) {
                            $user->assignRole(AdminRole::ADMIN->value);
                            $legacyAssigned++;
                        }
                    });

                if (! $this->databaseMatchesCatalog()) {
                    throw new RuntimeException('Backoffice RBAC synchronization did not produce the canonical state.');
                }

                return [
                    'permissions' => count($this->catalog->permissions()),
                    'roles' => count($this->catalog->roles()),
                    'role_permissions' => $rolePermissionCount,
                    'primary_admin_assigned' => $primaryAssigned,
                    'legacy_admins_assigned' => $legacyAssigned,
                ];
            }, 3);
        } finally {
            $this->clearPermissionCache();
        }

        return ['changed' => ! $wasSynchronized, ...$result];
    }

    private function primaryAdmin(): ?User
    {
        $email = $this->primaryAdminEmail();

        if ($email === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
    }

    private function legacyAdminsWithoutRolesQuery()
    {
        $query = User::query()
            ->where('role', UserRole::Admin->value)
            ->whereDoesntHave('roles');
        $primaryAdminEmail = $this->primaryAdminEmail();

        if ($primaryAdminEmail !== '') {
            $query->whereRaw('LOWER(email) <> ?', [$primaryAdminEmail]);
        }

        return $query;
    }

    private function primaryAdminEmail(): string
    {
        return mb_strtolower(trim((string) config('auth.primary_admin.email')));
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }

    private function clearPermissionCache(): void
    {
        $this->permissionRegistrar->forgetCachedPermissions();
        $this->permissionRegistrar->clearPermissionsCollection();
    }
}
