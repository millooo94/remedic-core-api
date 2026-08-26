<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Services\PublicLocaleResolver;
use App\Services\PublicSearchService;
use App\Services\PublicSearchTextNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SearchController extends Controller
{
    public function __construct(private readonly PublicLocaleResolver $locales, private readonly PublicSearchService $search, private readonly PublicSearchTextNormalizer $normalizer) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:150'],
            'locale' => ['nullable', 'string', Rule::in(array_map(fn (SupportedLocale $locale) => $locale->value, SupportedLocale::cases()))],
            'types' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        abort_if(mb_strlen($this->normalizer->normalize($data['q'])) < 2 || count($this->normalizer->tokens($data['q'])) > 12, 422, 'Query di ricerca non valida.');
        $locale = $this->locales->resolve($request);
        $types = array_values(array_filter(array_unique(array_map('trim', explode(',', $data['types'] ?? '')))));
        abort_if(array_diff($types, PublicSearchService::TYPES) !== [], 422, 'Tipo di ricerca non supportato.');
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 12);
        $results = $this->search->search($data['q'], $locale, $types, $page, $perPage);

        return response()->json(['data' => ['results' => $results['results'], 'locale' => $locale->value, 'page' => $page, 'per_page' => $perPage, 'total' => $results['total']]]);
    }
}
