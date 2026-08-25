<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\ConventionPartner;
use App\Models\Page;
use App\Models\User;
use App\Services\PageContentService;
use App\Support\Pages\PageSectionRegistry;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConventionPartnersAndPageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Role::findByName(AdminRole::ADMIN->value, 'web'));
        Sanctum::actingAs($user);
    }

    #[Test]
    public function conventions_crud_is_permission_protected_and_uses_the_shared_image_media_contract(): void
    {
        $this->assertTrue(Permission::findByName(AdminPermission::MANAGE_CONVENTIONS->value, 'web')->exists);
        $forbidden = User::factory()->create();
        Sanctum::actingAs($forbidden);
        $this->getJson('/api/v1/conventions')->assertForbidden();
        Sanctum::actingAs(User::query()->firstWhere('id', 1));

        $this->postJson('/api/v1/conventions', ['type' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'type']);
        $created = $this->postJson('/api/v1/conventions', ['name' => 'Partner B', 'type' => 'network', 'is_active' => false, 'sort_order' => 2])
            ->assertCreated()->assertJsonPath('type_label', 'Network');
        $id = (int) $created->json('id');

        Storage::fake('public');
        $this->post("/api/v1/conventions/{$id}/logo", ['image' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('id', $id);
        $this->putJson("/api/v1/conventions/{$id}", ['name' => 'Partner B aggiornato', 'type' => 'insurance', 'is_active' => true, 'sort_order' => 1])
            ->assertOk()->assertJsonPath('name', 'Partner B aggiornato');
        $this->deleteJson("/api/v1/conventions/{$id}/logo")->assertOk()->assertJsonPath('logo_path', null);
        $this->deleteJson("/api/v1/conventions/{$id}")->assertNoContent();
    }

    #[Test]
    public function convention_list_is_deterministic_and_inactive_records_remain_in_management(): void
    {
        ConventionPartner::query()->create(['name' => 'Zeta', 'type' => 'network', 'is_active' => true, 'sort_order' => 1]);
        ConventionPartner::query()->create(['name' => 'Alfa', 'type' => 'fund', 'is_active' => true, 'sort_order' => 1]);
        ConventionPartner::query()->create(['name' => 'Nascosta', 'type' => 'entity', 'is_active' => false, 'sort_order' => 0]);

        $this->getJson('/api/v1/conventions?per_page=50')->assertOk()
            ->assertJsonPath('data.0.name', 'Nascosta')
            ->assertJsonPath('data.1.name', 'Alfa')
            ->assertJsonPath('data.2.name', 'Zeta');
    }

    #[Test]
    public function conventions_page_is_closed_and_its_catalog_is_live_and_safe(): void
    {
        $page = $this->createPage(['published_at' => now()->subMinute()]);
        $sections = $page->sections()->ordered()->get()->keyBy('key');
        $this->assertSame(PageSectionRegistry::CONVENTIONS_NETWORK_SECTION_KEYS, $sections->keys()->all());
        $this->assertSame(['direct_booking', 'practice_management', 'agreement_conditions'], array_column($sections['access_process']->extra_json['items'], 'semantic_key'));

        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->pagePayload($page, ['sections' => [[
            'key' => 'conventions_catalog', 'title' => 'Catalogo', 'data' => ['intro' => 'Solo copy', 'items' => []],
        ]]]))->assertUnprocessable();
        $this->putJson("/api/v1/admin/pages/{$page->id}", $this->pagePayload($page, ['sections' => [[
            'key' => 'contact_cta', 'title' => 'Contatto', 'data' => ['body' => 'Testo', 'url' => 'https://example.test'],
        ]]]))->assertUnprocessable();

        $partner = ConventionPartner::query()->create(['name' => 'Visibile', 'type' => 'insurance', 'logo_path' => 'conventions/visible.png', 'is_active' => true, 'sort_order' => 5]);
        $second = ConventionPartner::query()->create(['name' => 'Seconda', 'type' => 'network', 'is_active' => true, 'sort_order' => 10]);
        ConventionPartner::query()->create(['name' => 'Inattiva', 'type' => 'network', 'is_active' => false, 'sort_order' => 0]);
        $pageUpdatedAt = $page->updated_at;
        $response = $this->getJson('/api/v1/public/pages/convenzioni-e-network')->assertOk()
            ->assertJsonPath('data.sections.2.data.items.0.name', 'Visibile')
            ->assertJsonPath('data.sections.2.data.available_types.0.type', 'insurance')
            ->assertJsonMissing(['logo_path', 'is_active', 'sort_order']);
        $this->assertStringContainsString('/storage/conventions/visible.png', (string) $response->json('data.sections.2.data.items.0.logo_url'));
        $partner->update(['name' => 'Rinominata', 'type' => 'fund', 'sort_order' => 20]);
        $this->getJson('/api/v1/public/pages/convenzioni-e-network')->assertOk()
            ->assertJsonPath('data.sections.2.data.items.0.name', 'Seconda')
            ->assertJsonPath('data.sections.2.data.items.1.name', 'Rinominata')
            ->assertJsonPath('data.sections.2.data.available_types.1.type', 'fund');
        $this->assertTrue($page->fresh()->updated_at->equalTo($pageUpdatedAt));
        $partner->update(['is_active' => false]);
        $second->update(['is_active' => false]);
        $this->getJson('/api/v1/public/pages/convenzioni-e-network')->assertOk()
            ->assertJsonPath('data.sections.2.data.items', [])
            ->assertJsonPath('data.sections.2.data.available_types', []);
    }

    /** @param array<string, mixed> $overrides */
    private function createPage(array $overrides = []): Page
    {
        $page = Page::query()->firstOrCreate(['slug' => Page::CONVENTIONS_NETWORK_SLUG], [
            'internal_key' => Page::CONVENTIONS_NETWORK_INTERNAL_KEY,
            'title' => 'Convenzioni e network',
            'template' => 'default',
            'faq_enabled' => false,
            'is_active' => true,
            'published_at' => null,
        ]);
        $page->update($overrides);
        app(PageContentService::class)->initializeMissingSections($page);

        return $page->fresh();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function pagePayload(Page $page, array $overrides = []): array
    {
        return ['title' => $page->title, 'slug' => $page->slug, 'template' => $page->template?->value ?? $page->template, 'faq_enabled' => false, 'is_active' => true, 'published_at' => optional($page->published_at)?->toIso8601String(), ...$overrides];
    }
}
