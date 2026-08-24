<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Specialization;
use App\Models\User;
use App\Services\BackofficeAccess\BackofficeAccessCatalog;
use App\Services\BackofficeAccess\BackofficeAccessSynchronizer;
use Database\Seeders\BackofficeAccessSeeder;
use Database\Seeders\BackofficePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BackofficeAccessSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY_ADMIN_EMAIL = 'primary-admin@example.test';

    private BackofficeAccessCatalog $catalog;

    private BackofficeAccessSynchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.primary_admin.email', self::PRIMARY_ADMIN_EMAIL);
        $this->catalog = app(BackofficeAccessCatalog::class);
        $this->synchronizer = app(BackofficeAccessSynchronizer::class);
    }

    #[Test]
    public function it_materializes_the_complete_canonical_catalog_and_exact_role_matrix(): void
    {
        $first = $this->synchronizer->synchronize();
        $permissionUpdatedAt = Permission::findByName(
            AdminPermission::MANAGE_PAGES->value,
            BackofficeAccessCatalog::GUARD_NAME,
        )->updated_at;
        $second = $this->synchronizer->synchronize();

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertEquals(
            $permissionUpdatedAt,
            Permission::findByName(
                AdminPermission::MANAGE_PAGES->value,
                BackofficeAccessCatalog::GUARD_NAME,
            )->updated_at,
        );
        $this->assertSameCanonicalValues(
            AdminPermission::values(),
            Permission::query()->where('guard_name', BackofficeAccessCatalog::GUARD_NAME)->pluck('name')->all(),
        );
        $this->assertSameCanonicalValues(
            array_map(static fn (AdminRole $role): string => $role->value, AdminRole::cases()),
            Role::query()->where('guard_name', BackofficeAccessCatalog::GUARD_NAME)->pluck('name')->all(),
        );

        foreach ($this->catalog->rolePermissions() as $roleName => $permissionNames) {
            $role = Role::findByName($roleName, BackofficeAccessCatalog::GUARD_NAME);
            $this->assertSameCanonicalValues($permissionNames, $role->permissions->pluck('name')->all());
        }

        $this->assertTrue($this->synchronizer->isSynchronized());
    }

    #[Test]
    public function it_assigns_primary_and_legacy_admins_without_promoting_explicit_roles(): void
    {
        $this->synchronizer->synchronize();

        $primary = User::factory()->create(['email' => self::PRIMARY_ADMIN_EMAIL]);
        $ordinaryAdmin = User::factory()->create(['email' => 'ordinary-admin@example.test']);
        $editor = User::factory()->create(['email' => 'editor@example.test', 'role' => UserRole::Admin]);
        $seoManager = User::factory()->create(['email' => 'seo@example.test', 'role' => UserRole::Admin]);
        $editor->assignRole(AdminRole::EDITOR->value);
        $seoManager->assignRole(AdminRole::SEO_MANAGER->value);

        $this->synchronizer->synchronize();
        $this->synchronizer->synchronize();

        $this->assertSame([AdminRole::SUPER_ADMIN->value], $primary->fresh()->getRoleNames()->all());
        $this->assertSame([AdminRole::ADMIN->value], $ordinaryAdmin->fresh()->getRoleNames()->all());
        $this->assertSame([AdminRole::EDITOR->value], $editor->fresh()->getRoleNames()->all());
        $this->assertSame([AdminRole::SEO_MANAGER->value], $seoManager->fresh()->getRoleNames()->all());
        $this->assertFalse($ordinaryAdmin->fresh()->hasRole(AdminRole::SUPER_ADMIN->value));
    }

    #[Test]
    public function it_preserves_noncanonical_records_but_removes_them_from_canonical_role_matrix(): void
    {
        $this->synchronizer->synchronize();
        $extraPermission = Permission::findOrCreate('custom retained permission', BackofficeAccessCatalog::GUARD_NAME);
        $extraRole = Role::findOrCreate('custom_retained_role', BackofficeAccessCatalog::GUARD_NAME);
        $admin = Role::findByName(AdminRole::ADMIN->value, BackofficeAccessCatalog::GUARD_NAME);
        $admin->givePermissionTo($extraPermission);

        $this->synchronizer->synchronize();

        $this->assertDatabaseHas('permissions', ['name' => $extraPermission->name]);
        $this->assertDatabaseHas('roles', ['name' => $extraRole->name]);
        $this->assertFalse($admin->fresh()->hasPermissionTo($extraPermission));
    }

    #[Test]
    public function it_repairs_restore_drift_before_auth_me_without_touching_business_data(): void
    {
        $primary = User::factory()->create(['email' => self::PRIMARY_ADMIN_EMAIL]);
        $specialization = Specialization::query()->create([
            'name' => 'Cardiologia test',
            'slug' => 'cardiologia-test',
            'is_active' => true,
        ]);
        Professional::factory()->create(['area_name' => $specialization->name]);
        $serviceCategory = ServiceCategory::factory()->create([
            'name' => 'Categoria RBAC test',
            'slug' => 'categoria-rbac-test',
        ]);
        Service::factory()->create([
            'category_id' => $serviceCategory->getKey(),
            'canonical_name' => 'Prestazione RBAC test',
            'display_name' => 'Prestazione RBAC test',
            'slug' => 'prestazione-rbac-test',
        ]);
        $this->synchronizer->synchronize();
        $before = $this->businessCounts();

        Permission::findByName(AdminPermission::MANAGE_DOCTORS->value, BackofficeAccessCatalog::GUARD_NAME)->delete();
        Role::findByName(AdminRole::SEO_MANAGER->value, BackofficeAccessCatalog::GUARD_NAME)->delete();
        $adminRole = Role::findByName(AdminRole::ADMIN->value, BackofficeAccessCatalog::GUARD_NAME);
        $centerPermission = Permission::findByName(AdminPermission::MANAGE_CENTER_SETTINGS->value, BackofficeAccessCatalog::GUARD_NAME);
        DB::table('role_has_permissions')
            ->where('role_id', $adminRole->getKey())
            ->where('permission_id', $centerPermission->getKey())
            ->delete();

        Sanctum::actingAs($primary);
        $response = $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('can_access_backoffice', true);

        $this->assertContains(AdminRole::SUPER_ADMIN->value, $response->json('backoffice_roles'));
        $this->assertContains(AdminPermission::MANAGE_DOCTORS->value, $response->json('backoffice_permissions'));
        $this->assertContains(AdminPermission::MANAGE_CENTER_SETTINGS->value, $response->json('backoffice_permissions'));
        $this->assertTrue($this->synchronizer->isSynchronized());
        $this->assertSame($before, $this->businessCounts());
    }

    #[Test]
    public function login_reconciles_missing_permissions_before_serializing_the_user(): void
    {
        User::factory()->create([
            'email' => self::PRIMARY_ADMIN_EMAIL,
            'password' => 'password',
        ]);
        $this->synchronizer->synchronize();
        Permission::findByName(AdminPermission::MANAGE_CENTER_SETTINGS->value, BackofficeAccessCatalog::GUARD_NAME)->delete();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::PRIMARY_ADMIN_EMAIL,
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertOk()->assertJsonPath('user.can_access_backoffice', true);

        $this->assertContains(
            AdminPermission::MANAGE_CENTER_SETTINGS->value,
            $response->json('user.backoffice_permissions'),
        );
        $this->assertCount(count(AdminPermission::values()), $response->json('user.backoffice_permissions'));
    }

    #[Test]
    public function newly_recreated_permissions_are_immediately_visible_after_cache_invalidation(): void
    {
        $admin = User::factory()->create(['email' => 'cache-admin@example.test']);
        $this->synchronizer->synchronize();
        app(PermissionRegistrar::class)->getPermissions();
        Permission::findByName(AdminPermission::MANAGE_SERVICES->value, BackofficeAccessCatalog::GUARD_NAME)->delete();

        $this->synchronizer->synchronize();
        $freshAdmin = $admin->fresh();

        $this->assertTrue($freshAdmin->can(AdminPermission::MANAGE_SERVICES->value));
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => Role::findByName(AdminRole::ADMIN->value, BackofficeAccessCatalog::GUARD_NAME)->getKey(),
            'permission_id' => Permission::findByName(AdminPermission::MANAGE_SERVICES->value, BackofficeAccessCatalog::GUARD_NAME)->getKey(),
        ]);
    }

    #[Test]
    public function primary_and_admin_can_access_center_and_equipe_while_an_unassigned_user_cannot(): void
    {
        $primary = User::factory()->create(['email' => self::PRIMARY_ADMIN_EMAIL]);
        $admin = User::factory()->create(['email' => 'web-admin@example.test']);
        $unassigned = User::factory()->create(['email' => 'unassigned@example.test']);
        $this->synchronizer->synchronize();

        Sanctum::actingAs($primary);
        $this->getJson('/api/v1/management/settings/center')->assertOk();
        $this->getJson('/api/v1/admin/equipe')->assertOk();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/management/settings/center')->assertOk();
        $this->getJson('/api/v1/admin/equipe')->assertOk();

        $unassigned->roles()->detach();
        Sanctum::actingAs($unassigned->fresh());
        $this->getJson('/api/v1/management/settings/center')->assertForbidden();
        $this->getJson('/api/v1/admin/equipe')->assertForbidden();
    }

    #[Test]
    public function the_command_is_idempotent_and_reports_only_non_sensitive_counts(): void
    {
        $this->artisan('backoffice:sync-access')
            ->expectsOutputToContain('Backoffice RBAC synchronized.')
            ->assertSuccessful();
        $this->artisan('backoffice:sync-access')
            ->expectsOutputToContain('Backoffice RBAC already synchronized.')
            ->assertSuccessful();
    }

    #[Test]
    public function both_compatibility_seeders_delegate_to_the_canonical_synchronizer(): void
    {
        $this->seed(BackofficePermissionSeeder::class);
        $this->assertTrue($this->synchronizer->isSynchronized());

        Permission::findByName(AdminPermission::MANAGE_PAGES->value, BackofficeAccessCatalog::GUARD_NAME)->delete();
        $this->seed(BackofficeAccessSeeder::class);

        $this->assertTrue($this->synchronizer->isSynchronized());
    }

    /**
     * @return array{professionals: int, services: int, specializations: int, users: int}
     */
    private function businessCounts(): array
    {
        return [
            'professionals' => Professional::query()->count(),
            'services' => Service::query()->count(),
            'specializations' => Specialization::query()->count(),
            'users' => User::query()->count(),
        ];
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     */
    private function assertSameCanonicalValues(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame(array_values($expected), array_values($actual));
    }
}
