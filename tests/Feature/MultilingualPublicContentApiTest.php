<?php

namespace Tests\Feature;

use App\Enums\SupportedLocale;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MultilingualPublicContentApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function supported_locales_are_strict_and_italian_has_no_prefix(): void
    {
        $this->assertSame(SupportedLocale::IT, SupportedLocale::default());
        $this->assertSame(SupportedLocale::EN, SupportedLocale::normalize('en-GB'));
        $this->assertNull(SupportedLocale::normalize('de'));
        $this->getJson('/api/v1/public/pages/anything?locale=de')->assertUnprocessable();
    }

    #[Test]
    public function published_translation_is_required_and_becomes_stale_when_the_italian_source_changes(): void
    {
        $page = Page::query()->create([
            'title' => 'Pagina italiana',
            'slug' => 'pagina-italiana',
            'template' => 'default',
            'is_active' => true,
            'published_at' => now(),
        ]);
        $italian = $page->translations()->where('locale', 'it')->firstOrFail();
        $english = $page->translations()->create([
            'locale' => 'en',
            'title' => 'English page',
            'slug' => 'english-page',
            'publication_state' => 'published',
            'source_revision' => $italian->source_revision,
            'reviewed_source_revision' => $italian->source_revision,
        ]);

        $this->getJson('/api/v1/public/pages/english-page?locale=en')
            ->assertOk()
            ->assertJsonPath('data.title', 'English page')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.available_locales', ['it', 'en']);
        $this->getJson('/api/v1/public/pages/pagina-italiana?locale=en')->assertNotFound();
        $this->getJson('/api/v1/public/pages/english-page?locale=fr')->assertNotFound();

        $page->update(['title' => 'Pagina italiana aggiornata']);
        $english->refresh();
        $this->assertNotSame($english->source_revision, $english->reviewed_source_revision);
        $this->getJson('/api/v1/public/pages/english-page?locale=en')->assertNotFound();

        $english->update(['reviewed_source_revision' => $english->source_revision]);
        $this->getJson('/api/v1/public/pages/english-page?locale=en')->assertOk();
    }
}
