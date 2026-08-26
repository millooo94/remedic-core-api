<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SearchSynonymGroupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    public function test_search_synonyms_require_permission_and_validate_then_support_crud(): void
    {
        $denied = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($denied);
        $this->getJson('/api/v1/admin/search-synonym-groups')->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $admin->givePermissionTo(AdminPermission::VIEW_BACKOFFICE->value);
        $admin->givePermissionTo(AdminPermission::MANAGE_SEARCH->value);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/search-synonym-groups', ['locale' => 'de', 'canonical_term' => '', 'is_active' => true, 'variants' => ['x']])->assertUnprocessable();
        $created = $this->postJson('/api/v1/admin/search-synonym-groups', ['locale' => 'it', 'canonical_term' => 'Ecografia', 'is_active' => true, 'variants' => ['Eco', 'ecografia']])
            ->assertCreated()->assertJsonPath('data.canonical_term', 'ecografia')->assertJsonPath('data.variants', ['eco']);
        $id = $created->json('data.id');
        $this->putJson('/api/v1/admin/search-synonym-groups/'.$id, ['locale' => 'it', 'canonical_term' => 'ecografia', 'is_active' => false, 'variants' => ['eco', 'ultrasuoni']])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.variants', ['eco', 'ultrasuoni']);
        $this->getJson('/api/v1/admin/search-synonym-groups')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson('/api/v1/admin/search-synonym-groups/'.$id)->assertNoContent();
    }
}
