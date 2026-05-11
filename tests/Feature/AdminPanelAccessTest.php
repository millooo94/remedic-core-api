<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.primary_admin.email', 'humancaretelemedicine@gmail.com');

        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function filament_admin_routes_are_no_longer_exposed(): void
    {
        $this->get('/admin/login')
            ->assertNotFound();

        $this->get('/admin/users')
            ->assertNotFound();
    }

    #[Test]
    public function guests_cannot_access_admin_api_routes(): void
    {
        $this->getJson('/api/v1/admin/users')
            ->assertUnauthorized();
    }

    #[Test]
    public function a_super_admin_can_access_user_management_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'humancaretelemedicine@gmail.com',
        ]);

        $user->assignRole(Role::findByName(AdminRole::SUPER_ADMIN->value, 'web'));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/users')
            ->assertOk();
    }

    #[Test]
    public function an_editor_cannot_access_user_management_via_admin_api(): void
    {
        $user = User::factory()->create([
            'email' => 'editor@example.com',
        ]);

        $user->assignRole(Role::findByName(AdminRole::EDITOR->value, 'web'));

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/users')
            ->assertForbidden();
    }
}
