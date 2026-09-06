<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Page;
use App\Models\SiteSetting;
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

class HomePageSupportingSectionsApiTest extends TestCase
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
    public function contact_and_newsletter_keep_editorial_data_separate_from_shared_center_and_actions(): void
    {
        SiteSetting::ensureSingleton()->update([
            'clinic_address' => 'Via Roma 1, Milano',
            'clinic_phone' => '+39 02 123456',
            'clinic_email' => 'info@example.test',
            'google_maps_url' => 'https://maps.example.test/remedic',
        ]);
        $contact = Page::query()->where('slug', 'contatti')->first();
        $contact ??= Page::query()->create(['internal_key' => 'contact', 'title' => 'Contatti', 'slug' => 'contatti', 'template' => 'default']);
        $contact->update(['internal_key' => 'contact', 'is_active' => true, 'published_at' => now()->subSecond()]);
        $contact->sections()->updateOrCreate(['key' => 'hero'], ['title' => 'Contatti', 'sort_order' => 0, 'is_active' => true, 'extra_json' => ['image_path' => 'pages/contact.jpg', 'image_alt' => 'Ingresso Remedic']]);
        $privacy = Page::query()->where('slug', 'privacy')->first();
        $privacy ??= Page::query()->create(['internal_key' => 'privacy', 'title' => 'Privacy Policy', 'slug' => 'privacy', 'template' => 'default']);
        $privacy->update(['internal_key' => 'privacy', 'is_active' => true, 'published_at' => now()->subSecond()]);
        $home = Page::query()->where('slug', Page::HOME_SLUG)->first();
        $home ??= Page::query()->create(['internal_key' => Page::HOME_INTERNAL_KEY, 'title' => 'Homepage', 'slug' => Page::HOME_SLUG, 'template' => 'default']);
        $home->update(['internal_key' => Page::HOME_INTERNAL_KEY, 'is_active' => true]);
        app(PageContentService::class)->initializeMissingSections($home);

        $admin = $this->getJson('/api/v1/admin/homepage')->assertOk();
        $contactData = collect($admin->json('sections'))->firstWhere('key', 'contact')['data'];
        $faqData = collect($admin->json('sections'))->firstWhere('key', 'faq')['data'];
        $this->assertSame('booking', $contactData['primary_cta_target']);
        $this->assertSame('map', $contactData['secondary_cta_target']);
        $this->assertSame('Via Roma 1, Milano', $contactData['center']['address']['formatted_address']);
        $this->assertStringContainsString('/storage/pages/contact.jpg', $contactData['shared_media']['url']);
        $this->assertSame('contact', $faqData['cta_target']);

        $this->putJson("/api/v1/admin/pages/{$home->id}", [
            'title' => 'Homepage',
            'sections' => [[
                'key' => 'faq', 'title' => 'FAQ', 'sort_order' => 8, 'is_active' => true,
                'data' => ['title' => 'Domande frequenti', 'cta_label' => 'Contatta il centro', 'cta_target' => 'contact'],
            ], [
                'key' => 'contact', 'title' => 'Contatti', 'sort_order' => 9, 'is_active' => true,
                'data' => ['title' => 'Raggiungici facilmente', 'primary_cta_label' => 'Prenota ora', 'primary_cta_target' => 'booking', 'secondary_cta_label' => 'Indicazioni', 'secondary_cta_target' => 'map'],
            ], [
                'key' => 'newsletter', 'title' => 'Newsletter', 'sort_order' => 10, 'is_active' => true,
                'data' => ['title' => 'Resta aggiornato', 'benefits' => ['Uno', 'Due', 'Tre'], 'email_placeholder' => 'mail@example.test', 'submit_label' => 'Iscriviti', 'submit_target' => 'newsletter_subscription'],
            ]],
        ])->assertOk();

        $contactSection = $home->fresh()->sections()->where('key', 'contact')->firstOrFail();
        $this->assertSame('booking', $contactSection->extra_json['primary_cta_target']);
        $this->assertArrayNotHasKey('center', $contactSection->extra_json);
        $this->assertArrayNotHasKey('shared_media', $contactSection->extra_json);

        $sections = collect(app(HomePagePublicProjection::class)->project($home->fresh(), Request::create('/'))['sections']);
        $faq = $sections->firstWhere('key', 'faq')['data'];
        $this->assertSame(['label' => 'Contatta il centro', 'href' => '/contatti'], $faq['cta']);
        $publicContact = $sections->firstWhere('key', 'contact')['data'];
        $this->assertSame(['label' => 'Prenota ora', 'action' => 'booking'], $publicContact['primary_cta']);
        $this->assertSame(['label' => 'Indicazioni', 'action' => 'map', 'href' => 'https://maps.example.test/remedic'], $publicContact['secondary_cta']);
        $this->assertSame('Via Roma 1, Milano', $publicContact['center']['address']['formatted_address']);

        $newsletter = $sections->firstWhere('key', 'newsletter')['data'];
        $this->assertSame(['Uno', 'Due', 'Tre'], $newsletter['benefits']);
        $this->assertSame(['label' => 'Iscriviti', 'action' => 'newsletter_subscription'], $newsletter['submit_action']);
        $this->assertSame(['label' => 'Privacy Policy', 'href' => '/privacy'], $newsletter['privacy_action']);
    }
}
