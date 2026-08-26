<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\ConsentEventType;
use App\Enums\UserRole;
use App\Models\ConsentConfiguration;
use App\Models\ConsentRecord;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConsentManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    #[Test]
    public function configuration_is_a_disabled_singleton_with_fixed_categories(): void
    {
        Page::query()->where('internal_key', 'privacy')->update(['is_active' => true, 'published_at' => now()]);
        Page::query()->where('internal_key', 'cookie-policy')->update(['is_active' => true, 'published_at' => now()]);
        $this->getJson('/api/v1/public/consent/configuration')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.configuration_version', 1)->assertJsonPath('data.categories.0.key', 'necessary')->assertJsonPath('data.categories.0.required', true)->assertJsonPath('data.categories.2.key', 'statistics')->assertJsonPath('data.privacy.href', '/privacy')->assertJsonPath('data.cookie_policy.href', '/cookie-policy');
        $this->assertSame(1, ConsentConfiguration::query()->count());
    }

    #[Test]
    public function public_consents_are_pseudonymous_hashed_and_versioned(): void
    {
        $created = $this->postJson('/api/v1/public/consents', ['configuration_version' => 1, 'preferences' => true, 'statistics' => false, 'marketing' => false])->assertCreated()->assertJsonPath('data.necessary', true)->assertJsonPath('data.requires_renewal', false);
        $id = $created->json('data.public_id');
        $token = $created->json('data.management_token');
        $record = ConsentRecord::query()->firstOrFail();
        $this->assertSame($id, $record->public_id);
        $this->assertNotSame($token, $record->management_token_hash);
        $this->assertSame(ConsentEventType::CREATED, $record->events()->firstOrFail()->event_type);
        $this->getJson('/api/v1/public/consents/'.$id)->assertForbidden();
        $this->getJson('/api/v1/public/consents/'.$id, ['X-Consent-Token' => $token])->assertOk()->assertJsonMissingPath('data.management_token_hash');
        $this->patchJson('/api/v1/public/consents/'.$id, ['configuration_version' => 1, 'preferences' => false, 'statistics' => false, 'marketing' => false], ['X-Consent-Token' => $token])->assertOk();
        $this->assertSame(ConsentEventType::WITHDRAWN, $record->fresh()->events()->reorder()->latest('id')->firstOrFail()->event_type);
    }

    #[Test]
    public function stale_submissions_are_rejected_and_renewal_preserves_history(): void
    {
        $created = $this->postJson('/api/v1/public/consents', ['configuration_version' => 1, 'preferences' => false, 'statistics' => false, 'marketing' => false]);
        $id = $created->json('data.public_id');
        $token = $created->json('data.management_token');
        $this->admin();
        $this->postJson('/api/v1/admin/consent-configuration/publish-version')->assertOk()->assertJsonPath('data.configuration_version', 2);
        $this->getJson('/api/v1/public/consents/'.$id, ['X-Consent-Token' => $token])->assertJsonPath('data.requires_renewal', true);
        $this->patchJson('/api/v1/public/consents/'.$id, ['configuration_version' => 1, 'preferences' => true, 'statistics' => true, 'marketing' => true], ['X-Consent-Token' => $token])->assertConflict();
        $this->patchJson('/api/v1/public/consents/'.$id, ['configuration_version' => 2, 'preferences' => true, 'statistics' => true, 'marketing' => true], ['X-Consent-Token' => $token])->assertOk()->assertJsonPath('data.requires_renewal', false);
        $this->assertSame(ConsentEventType::RENEWED, ConsentRecord::query()->firstOrFail()->events()->reorder()->latest('id')->firstOrFail()->event_type);
    }

    #[Test]
    public function admin_configuration_and_read_only_records_have_split_permissions(): void
    {
        $this->getJson('/api/v1/admin/consent-configuration')->assertUnauthorized();
        $this->admin();
        $this->putJson('/api/v1/admin/consent-configuration', ['is_enabled' => true])->assertOk()->assertJsonPath('data.is_enabled', true);
        $this->getJson('/api/v1/admin/consent-records')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/public/navigation')->assertOk()->assertJsonFragment(['action' => 'cookie_preferences']);
    }

    private function admin(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }
}
