<?php

namespace Tests\Feature;

use App\Enums\SupportedLocale;
use App\Models\SearchDocument;
use App\Models\SearchSynonymGroup;
use App\Services\PublicSearchService;
use App\Services\PublicSearchTextNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_exact_titles_and_supports_fuzzy_and_synonym_candidates(): void
    {
        $exact = $this->document('Cardiologia', 'La cardiologia cura il cuore');
        $this->document('Pagina generica', 'Cardiologia nel contenuto');
        $group = SearchSynonymGroup::query()->create(['locale' => 'it', 'canonical_term' => 'ecografia', 'is_active' => true]);
        $group->synonyms()->create(['term' => 'eco']);
        $this->document('Ecografia', 'Esame diagnostico');

        $service = app(PublicSearchService::class);
        $this->assertSame('Cardiologia', $service->search('CARDIOLOGIA', SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
        $this->assertSame('Cardiologia', $service->search('cardiologa', SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
        $this->assertSame('Ecografia', $service->search('eco', SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
        $this->assertSame($exact->href, $service->search('cardio', SupportedLocale::IT, ['service'], 1, 10)['results'][0]['href']);
    }

    public function test_public_endpoint_rejects_queries_without_useful_characters(): void
    {
        $this->getJson('/api/v1/public/search?q=%20%20')->assertUnprocessable();
        $this->getJson('/api/v1/public/search?q=--')->assertUnprocessable();
    }

    private function document(string $title, string $text): SearchDocument
    {
        $normalized = app(PublicSearchTextNormalizer::class)->normalize($title.' '.$text);
        $normalizer = app(PublicSearchTextNormalizer::class);
        $document = SearchDocument::query()->create(['source_type' => 'test', 'source_id' => SearchDocument::query()->count() + 1, 'locale' => 'it', 'result_type' => 'service', 'href' => '/prestazioni/'.strtolower(str_replace(' ', '-', $title)), 'title' => $title, 'normalized_title' => $normalizer->normalize($title), 'normalized_text' => $normalized, 'searchable_tokens' => $normalized]);
        $document->ngrams()->createMany(array_map(fn (string $gram): array => ['gram' => $gram], $normalizer->trigrams($title)));

        return $document;
    }
}
