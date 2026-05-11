<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class BackofficePermissionSeeder extends Seeder
{
    public const GUARD_NAME = 'web';

    public function run(): void
    {
        $permissionRegistrar = app(PermissionRegistrar::class);

        $permissionRegistrar->forgetCachedPermissions();
        $permissionRegistrar->clearPermissionsCollection();

        foreach (AdminPermission::cases() as $permission) {
            Permission::query()->updateOrCreate(
                [
                    'name' => $permission->value,
                    'guard_name' => self::GUARD_NAME,
                ],
                [],
            );
        }

        $permissionRegistrar->forgetCachedPermissions();
        $permissionRegistrar->clearPermissionsCollection();
    }
}
