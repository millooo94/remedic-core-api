<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageReview;
use App\Services\PageReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageReviewController extends Controller
{
    public function __construct(private readonly PageReviewService $reviews) {}

    public function index(Page $page): JsonResponse
    {
        $this->reviews->assertWhyChooseUs($page);

        return response()->json(['data' => $this->payload($page)]);
    }

    public function syncGoogle(Page $page): JsonResponse
    {
        $this->reviews->syncGoogle($page);

        return response()->json(['data' => $this->payload($page->fresh())]);
    }

    public function storeMiodottore(Request $request, Page $page): JsonResponse
    {
        $this->reviews->assertWhyChooseUs($page);
        $data = $request->validate(['author_name' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'max:5000']]);
        $page->reviews()->create([...$data, 'provider' => PageReviewService::MIODOTTORE, 'is_available' => true]);

        return response()->json(['data' => $this->payload($page)], 201);
    }

    public function updateMiodottore(Request $request, Page $page, PageReview $review): JsonResponse
    {
        $this->reviews->assertWhyChooseUs($page);
        abort_unless($review->page_id === $page->id && $review->provider === PageReviewService::MIODOTTORE, 404);
        $review->update($request->validate(['author_name' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'max:5000']]));

        return response()->json(['data' => $this->payload($page)]);
    }

    public function destroyMiodottore(Page $page, PageReview $review): JsonResponse
    {
        $this->reviews->assertWhyChooseUs($page);
        abort_unless($review->page_id === $page->id && $review->provider === PageReviewService::MIODOTTORE, 404);
        $review->delete();

        return response()->json(['data' => $this->payload($page)]);
    }

    public function feature(Request $request, Page $page): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in([PageReviewService::GOOGLE, PageReviewService::MIODOTTORE])],
            'review_id' => ['nullable', 'integer'],
        ]);
        $review = isset($data['review_id']) ? PageReview::findOrFail($data['review_id']) : null;
        $this->reviews->setFeatured($page, $data['provider'], $review);

        return response()->json(['data' => $this->payload($page)]);
    }

    /** @return array<string, mixed> */
    private function payload(Page $page): array
    {
        $featured = $page->featuredReviews()->pluck('page_review_id', 'provider');

        return [
            'google' => $this->map($page->reviews()->where('provider', PageReviewService::GOOGLE)->latest('reviewed_at')->get(), $featured->get(PageReviewService::GOOGLE)),
            'miodottore' => $this->map($page->reviews()->where('provider', PageReviewService::MIODOTTORE)->latest()->get(), $featured->get(PageReviewService::MIODOTTORE)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function map(iterable $reviews, ?int $featuredId): array
    {
        return collect($reviews)->map(fn (PageReview $review): array => [
            'id' => $review->id, 'provider' => $review->provider, 'author_name' => $review->author_name,
            'body' => $review->body, 'rating' => $review->rating, 'reviewed_at' => $review->reviewed_at?->toIso8601String(),
            'synced_at' => $review->synced_at?->toIso8601String(), 'is_available' => $review->is_available,
            'is_featured' => $review->id === $featuredId,
        ])->values()->all();
    }
}
