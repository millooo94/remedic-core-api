<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\ConventionPartner;
use App\Models\Page;
use App\Models\User;
use App\Services\HomePagePublicProjection;
use App\Services\PageContentService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomePageConventionsApiTest extends TestCase
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
    public function homepage_conventions_persist_only_master_references_and_project_their_live_public_data(): void
    {
        Page::query()->create([
            'internal_key' => Page::CONVENTIONS_NETWORK_INTERNAL_KEY,
            'title' => 'Convenzioni e network',
            'slug' => Page::CONVENTIONS_NETWORK_SLUG,
            'template' => 'default',
            'is_active' => true,
            'published_at' => now()->subSecond(),
        ]);
        $home = Page::query()->create([
            'internal_key' => Page::HOME_INTERNAL_KEY,
            'title' => 'Homepage',
            'slug' => Page::HOME_SLUG,
            'template' => 'default',
            'is_active' => true,
        ]);
        app(PageContentService::class)->initializeMissingSections($home);

        $adminSection = collect($this->getJson('/api/v1/admin/homepage')->assertOk()->json('sections'))->firstWhere('key', 'conventions');
        $this->assertSame('conventions_network', $adminSection['data']['cta_target']);
        $this->assertSame('Altri network', $adminSection['data']['other_networks_title']);

        $first = ConventionPartner::query()->create(['name' => 'Fondo Uno', 'type' => 'fund', 'logo_path' => 'conventions/uno.png', 'is_active' => true, 'sort_order' => 1]);
        $second = ConventionPartner::query()->create(['name' => 'Assicurazione Due', 'type' => 'insurance', 'logo_path' => 'conventions/due.png', 'is_active' => true, 'sort_order' => 2]);
        $inactive = ConventionPartner::query()->create(['name' => 'Non disponibile', 'type' => 'network', 'is_active' => false, 'sort_order' => 3]);

        $payload = $this->payload($home, [
            'title' => 'Convenzioni in evidenza',
            'data' => [
                'title' => 'Ci prendiamo cura anche della parte burocratica',
                'cta_label' => 'Scopri tutte le convenzioni',
                'cta_target' => 'conventions_network',
                'partner_ids' => [$second->id, $first->id],
                'other_networks_title' => 'Altri network',
                'other_networks_body' => 'Network gestiti dal centro.',
            ],
        ]);
        $this->putJson("/api/v1/admin/pages/{$home->id}", $payload)->assertOk();

        $section = $home->fresh()->sections()->where('key', 'conventions')->firstOrFail();
        $this->assertSame('Convenzioni in evidenza', $section->title);
        $this->assertSame([$second->id, $first->id], $section->extra_json['partner_ids']);
        $this->assertArrayNotHasKey('name', $section->extra_json);
        $this->assertArrayNotHasKey('logo_url', $section->extra_json);

        $projected = collect(app(HomePagePublicProjection::class)->project($home->fresh(), Request::create('/'))['sections'])->firstWhere('key', 'conventions');
        $this->assertSame(['Assicurazione Due', 'Fondo Uno'], array_column($projected['data']['featured_partners'], 'name'));
        $this->assertStringContainsString('/storage/conventions/due.png', $projected['data']['featured_partners'][0]['logo_url']);
        $this->assertSame(['label' => 'Scopri tutte le convenzioni', 'href' => '/convenzioni-e-network'], $projected['data']['cta']);
        $this->assertSame('Altri network', $projected['data']['other_networks_title']);

        $invalid = $this->payload($home, ['data' => ['partner_ids' => [$inactive->id]]]);
        $this->putJson("/api/v1/admin/pages/{$home->id}", $invalid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sections.0.data.partner_ids');

        $second->update(['is_active' => false]);
        $this->putJson("/api/v1/admin/pages/{$home->id}", $payload)->assertOk();
        $this->assertSame([$second->id, $first->id], $home->fresh()->sections()->where('key', 'conventions')->firstOrFail()->extra_json['partner_ids']);
    }

    /** @param array<string, mixed> $section */
    private function payload(Page $page, array $section): array
    {
        return [
            'title' => $page->title,
            'sections' => [[
                'key' => 'conventions',
                'title' => $section['title'] ?? 'Convenzioni',
                'sort_order' => 8,
                'is_active' => true,
                'data' => $section['data'] ?? [],
            ]],
        ];
    }
}
