<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use App\Models\SearchDocument;
use App\Models\SearchSynonymGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PublicSearchService
{
    public const TYPES = ['page', 'index', 'medical_area', 'professional', 'service', 'checkup', 'news', 'health_pill'];

    public function __construct(private readonly PublicSearchTextNormalizer $normalizer) {}

    /** @return array{results:list<array<string,mixed>>,total:int} */
    public function search(string $query, SupportedLocale $locale, array $types, int $page, int $perPage): array
    {
        $normalized = $this->normalizer->normalize($query);
        $tokens = $this->normalizer->tokens($query);
        $terms = $this->expandedTerms($normalized, $locale);
        $grams = array_slice($this->normalizer->trigrams($normalized), 0, 12);
        $documents = SearchDocument::query()->where('locale', $locale->value)
            ->when($types !== [], fn (Builder $builder) => $builder->whereIn('result_type', $types))
            ->where(function (Builder $builder) use ($terms, $tokens, $grams): void {
                foreach ($terms as $term) {
                    $builder->orWhere('normalized_title', $term)
                        ->orWhere('normalized_title', 'like', $term.'%')
                        ->orWhere('searchable_tokens', 'like', '%'.$term.'%')
                        ->orWhere('normalized_text', 'like', '%'.$term.'%');
                }
                foreach ($tokens as $token) {
                    $builder->orWhere('searchable_tokens', 'like', $token.'%');
                }
                if ($grams !== []) {
                    $builder->orWhereHas('ngrams', fn (Builder $ngrams) => $ngrams->whereIn('gram', $grams));
                }
                if (DB::connection()->getDriverName() === 'mysql' && $terms !== []) {
                    $builder->orWhereRaw('MATCH(normalized_title, normalized_text) AGAINST(? IN NATURAL LANGUAGE MODE)', [$terms[0]]);
                }
            })->with('ngrams')->limit(250)->get();
        $ranked = $documents->map(fn (SearchDocument $document) => ['document' => $document, 'score' => $this->score($document, $normalized, $tokens, $terms)])
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortBy([['score', 'desc'], ['document.result_type', 'asc'], ['document.title', 'asc'], ['document.id', 'asc']])->values();
        $total = $ranked->count();

        return ['total' => $total, 'results' => $ranked->slice(($page - 1) * $perPage, $perPage)->map(fn (array $row): array => $this->payload($row['document']))->values()->all()];
    }

    /** @return list<string> */
    private function expandedTerms(string $normalized, SupportedLocale $locale): array
    {
        $groups = SearchSynonymGroup::query()->with('synonyms')->where('locale', $locale->value)->where('is_active', true)
            ->get()->filter(fn (SearchSynonymGroup $group): bool => $group->canonical_term === $normalized || $group->synonyms->contains('term', $normalized));

        return array_values(array_unique([$normalized, ...$groups->flatMap(fn (SearchSynonymGroup $group) => [$group->canonical_term, ...$group->synonyms->pluck('term')->all()])->map(fn (string $term) => $this->normalizer->normalize($term))->all()]));
    }

    private function score(SearchDocument $document, string $query, array $tokens, array $terms): int
    {
        $title = $document->normalized_title;
        $score = 0;
        if ($title === $query) {
            $score += 10000;
        }
        if (in_array($title, $terms, true) && $title !== $query) {
            $score += 4000;
        }
        if (str_starts_with($title, $query)) {
            $score += 3000;
        }
        foreach ($tokens as $token) {
            if (in_array($token, explode(' ', $title), true)) {
                $score += 1200;
            } elseif (str_contains($title, $token)) {
                $score += 800;
            } elseif (str_contains($document->normalized_text, $token)) {
                $score += 120;
            }
        }
        $distance = levenshtein(str_replace(' ', '', $query), str_replace(' ', '', $title));
        $limit = max(1, (int) floor(max(strlen($query), strlen($title)) * 0.28));
        if ($distance <= $limit) {
            $score += 700 - ($distance * 80);
        }
        $queryGrams = $this->normalizer->trigrams($query);
        if ($queryGrams !== []) {
            $titleGrams = $this->normalizer->trigrams($title);
            $overlap = count(array_intersect($queryGrams, $titleGrams));
            $score += (int) floor(400 * (($overlap * 2) / max(1, count($queryGrams) + count($titleGrams))));
        }

        return $score;
    }

    /** @return array<string,mixed> */
    private function payload(SearchDocument $document): array
    {
        return array_filter(['type' => $document->result_type, 'locale' => $document->locale, 'title' => $document->title, 'subtitle' => $document->subtitle, 'excerpt' => $document->excerpt, 'href' => $document->href, 'image' => $document->image_path], fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
