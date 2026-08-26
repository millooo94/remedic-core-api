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

    public function test_normalization_is_case_accent_whitespace_and_punctuation_insensitive_without_changing_payload(): void
    {
        $this->document('Più Salute', 'Ginecología e benessere');
        $service = app(PublicSearchService::class);

        foreach (['PIU SALUTE', '  più   salute ', 'piu-salute', "piu'salute"] as $query) {
            $this->assertSame('Più Salute', $service->search($query, SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
        }
        $this->assertSame('Più Salute', $service->search('ginecologia', SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
    }

    public function test_fuzzy_matching_uses_indexed_ngrams_and_rejects_distant_terms(): void
    {
        foreach (['Dermatologia', 'Ecografia', 'Liguori', 'Spitaleri'] as $title) {
            $this->document($title, 'Contenuto pubblico');
        }
        $service = app(PublicSearchService::class);

        foreach (['dermatolgia' => 'Dermatologia', 'ecografai' => 'Ecografia', 'liguor' => 'Liguori', 'spitaler' => 'Spitaleri'] as $query => $expected) {
            $this->assertSame($expected, $service->search($query, SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
        }
        $this->assertSame([], $service->search('zzzzzz', SupportedLocale::IT, [], 1, 10)['results']);
    }

    public function test_ranking_is_deterministic_and_prioritizes_exact_title_over_body_and_synonym(): void
    {
        $this->document('Pagina generica', 'cardiologia nel testo lungo');
        $this->document('Cardiologia', 'Titolo esatto');
        $this->document('Cardio visita', 'Prefix');
        $this->document('Ecografia', 'Sinonimo');
        $group = SearchSynonymGroup::query()->create(['locale' => 'it', 'canonical_term' => 'ecografia', 'is_active' => true]);
        $group->synonyms()->create(['term' => 'cardio']);
        $service = app(PublicSearchService::class);

        $first = $service->search('cardiologia', SupportedLocale::IT, [], 1, 10)['results'];
        $second = $service->search('cardiologia', SupportedLocale::IT, [], 1, 10)['results'];
        $this->assertSame('Cardiologia', $first[0]['title']);
        $this->assertSame(array_column($first, 'title'), array_column($second, 'title'));
        $this->assertSame('Cardio visita', $service->search('cardio', SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
    }

    public function test_synonyms_are_enabled_and_scoped_to_the_requested_locale(): void
    {
        $this->document('Ecografia', 'Esame', 'service', 'it');
        $this->document('Ultrasound', 'Exam', 'service', 'en');
        $it = SearchSynonymGroup::query()->create(['locale' => 'it', 'canonical_term' => 'ecografia', 'is_active' => true]);
        $it->synonyms()->create(['term' => 'eco']);
        $en = SearchSynonymGroup::query()->create(['locale' => 'en', 'canonical_term' => 'ultrasound', 'is_active' => false]);
        $en->synonyms()->create(['term' => 'eco']);
        $service = app(PublicSearchService::class);

        $this->assertSame('Ecografia', $service->search('eco', SupportedLocale::IT, [], 1, 10)['results'][0]['title']);
        $this->assertSame([], $service->search('eco', SupportedLocale::EN, [], 1, 10)['results']);
        $en->update(['is_active' => true]);
        $this->assertSame('Ultrasound', $service->search('eco', SupportedLocale::EN, [], 1, 10)['results'][0]['title']);
    }

    public function test_all_public_result_types_keep_the_typed_safe_contract(): void
    {
        foreach (PublicSearchService::TYPES as $type) {
            $this->document('Tipo '.$type, 'Parola comune', $type, 'it', '/'.$type.'/slug');
        }
        $results = app(PublicSearchService::class)->search('parola comune', SupportedLocale::IT, [], 1, 20)['results'];

        $types = array_values(array_unique(array_column($results, 'type')));
        sort($types);
        $expected = PublicSearchService::TYPES;
        sort($expected);
        $this->assertSame($expected, $types);
        foreach ($results as $result) {
            $this->assertArrayHasKey('href', $result);
            $this->assertArrayNotHasKey('source_type', $result);
            $this->assertSame('it', $result['locale']);
        }
    }

    public function test_type_filters_and_pagination_are_stable_and_non_overlapping(): void
    {
        foreach (range(1, 5) as $number) {
            $this->document('Servizio '.$number, 'cuore', 'service', 'it', '/prestazioni/'.$number);
        }
        $this->document('Dottore Cuore', 'cuore', 'professional');
        $service = app(PublicSearchService::class);
        $pageOne = $service->search('cuore', SupportedLocale::IT, ['service'], 1, 2);
        $pageTwo = $service->search('cuore', SupportedLocale::IT, ['service'], 2, 2);

        $this->assertSame(5, $pageOne['total']);
        $this->assertCount(2, $pageOne['results']);
        $this->assertEmpty(array_intersect(array_column($pageOne['results'], 'href'), array_column($pageTwo['results'], 'href')));
        $this->assertSame(['service'], array_values(array_unique(array_column($pageOne['results'], 'type'))));
    }

    public function test_public_endpoint_validates_boundaries_filters_and_zero_results_without_authentication(): void
    {
        $this->getJson('/api/v1/public/search')->assertUnprocessable();
        $this->getJson('/api/v1/public/search?q=a')->assertUnprocessable();
        $this->getJson('/api/v1/public/search?q='.str_repeat('a', 151))->assertUnprocessable();
        $this->getJson('/api/v1/public/search?q=ok&locale=de')->assertUnprocessable();
        $this->getJson('/api/v1/public/search?q=ok&types=private')->assertStatus(422);
        $this->getJson('/api/v1/public/search?q=ok&per_page=31')->assertUnprocessable();
        $this->getJson('/api/v1/public/search?q=nessun-risultato&page=1&per_page=2')->assertOk()->assertJsonPath('data.results', []);
    }

    private function document(string $title, string $text, string $type = 'service', string $locale = 'it', ?string $href = null): SearchDocument
    {
        $normalized = app(PublicSearchTextNormalizer::class)->normalize($title.' '.$text);
        $normalizer = app(PublicSearchTextNormalizer::class);
        $document = SearchDocument::query()->create(['source_type' => 'test', 'source_id' => SearchDocument::query()->count() + 1, 'locale' => $locale, 'result_type' => $type, 'href' => $href ?? '/prestazioni/'.strtolower(str_replace(' ', '-', $title)), 'title' => $title, 'normalized_title' => $normalizer->normalize($title), 'normalized_text' => $normalized, 'searchable_tokens' => $normalized]);
        $document->ngrams()->createMany(array_map(fn (string $gram): array => ['gram' => $gram], $normalizer->trigrams($title)));

        return $document;
    }
}
