<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\SearchSynonymGroup;
use App\Services\PublicSearchTextNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SearchSynonymGroupController extends Controller
{
    public function __construct(private readonly PublicSearchTextNormalizer $normalizer) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => SearchSynonymGroup::query()->with('synonyms')->orderBy('locale')->orderBy('canonical_term')->get()->map(fn (SearchSynonymGroup $group) => $this->payload($group))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $group = SearchSynonymGroup::query()->create($this->validated($request));
        $this->syncTerms($group, $request->input('variants', []));

        return response()->json(['data' => $this->payload($group->fresh('synonyms'))], 201);
    }

    public function update(Request $request, SearchSynonymGroup $searchSynonymGroup): JsonResponse
    {
        $searchSynonymGroup->update($this->validated($request));
        $this->syncTerms($searchSynonymGroup, $request->input('variants', []));

        return response()->json(['data' => $this->payload($searchSynonymGroup->fresh('synonyms'))]);
    }

    public function destroy(SearchSynonymGroup $searchSynonymGroup): JsonResponse
    {
        $searchSynonymGroup->delete();

        return response()->json(status: 204);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_map(fn (SupportedLocale $locale) => $locale->value, SupportedLocale::cases()))],
            'canonical_term' => ['required', 'string', 'max:150'], 'is_active' => ['required', 'boolean'],
            'variants' => ['nullable', 'array', 'max:20'], 'variants.*' => ['string', 'min:2', 'max:150'],
        ]);
        $data['canonical_term'] = $this->normalizer->normalize($data['canonical_term']);
        unset($data['variants']);

        return $data;
    }

    private function syncTerms(SearchSynonymGroup $group, array $variants): void
    {
        $terms = collect($variants)->map(fn (string $term) => $this->normalizer->normalize($term))->filter()->reject(fn (string $term) => $term === $group->canonical_term)->unique()->values();
        $group->synonyms()->whereNotIn('term', $terms)->delete();
        foreach ($terms as $term) {
            $group->synonyms()->firstOrCreate(['term' => $term]);
        }
    }

    private function payload(SearchSynonymGroup $group): array
    {
        return ['id' => $group->id, 'locale' => $group->locale->value, 'canonical_term' => $group->canonical_term, 'is_active' => $group->is_active, 'variants' => $group->synonyms->pluck('term')->values()->all()];
    }
}
