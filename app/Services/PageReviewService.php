<?php

namespace App\Services;

use App\Models\Page;
use App\Models\PageFeaturedReview;
use App\Models\PageReview;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PageReviewService
{
    public const GOOGLE = 'google';

    public const MIODOTTORE = 'miodottore';

    public function assertWhyChooseUs(Page $page): void
    {
        if ($page->internal_key !== Page::WHY_CHOOSE_US_INTERNAL_KEY) {
            throw ValidationException::withMessages(['page' => 'Le recensioni sono disponibili solo per la pagina Perché scegliere Remedic.']);
        }
    }

    /** @return list<PageReview> */
    public function syncGoogle(Page $page): array
    {
        $this->assertWhyChooseUs($page);
        $endpoint = config('services.google_reviews.endpoint');
        $token = config('services.google_reviews.access_token');
        if (! is_string($endpoint) || $endpoint === '' || ! is_string($token) || $token === '') {
            throw ValidationException::withMessages(['google_reviews' => 'La configurazione Google Reviews non è disponibile.']);
        }

        try {
            $response = Http::acceptJson()->withToken($token)
                ->timeout((int) config('services.google_reviews.timeout_seconds', 10))
                ->get($endpoint);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['google_reviews' => 'Il provider Google Reviews non è raggiungibile.']);
        }
        if (! $response->successful() || ! is_array($response->json('reviews'))) {
            throw ValidationException::withMessages(['google_reviews' => 'Il provider Google Reviews ha restituito una risposta non valida.']);
        }

        $page->reviews()->where('provider', self::GOOGLE)->update(['is_available' => false]);
        $syncedAt = now();
        foreach ($response->json('reviews') as $item) {
            if (! is_array($item) || ! filled($item['reviewId'] ?? null)) {
                continue;
            }
            $rating = match ($item['starRating'] ?? null) {
                'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5,
                default => is_numeric($item['starRating'] ?? null) ? (int) $item['starRating'] : null,
            };
            $page->reviews()->updateOrCreate(
                ['provider' => self::GOOGLE, 'external_id' => (string) $item['reviewId']],
                [
                    'author_name' => (string) data_get($item, 'reviewer.displayName', 'Google user'),
                    'body' => (string) ($item['comment'] ?? ''),
                    'rating' => $rating !== null && $rating >= 1 && $rating <= 5 ? $rating : null,
                    'reviewed_at' => filled($item['createTime'] ?? null) ? Carbon::parse($item['createTime']) : null,
                    'source_metadata' => array_filter(['update_time' => $item['updateTime'] ?? null]),
                    'synced_at' => $syncedAt,
                    'is_available' => true,
                ],
            );
        }

        return $page->reviews()->where('provider', self::GOOGLE)->latest('reviewed_at')->get()->all();
    }

    public function setFeatured(Page $page, string $provider, ?PageReview $review): void
    {
        $this->assertWhyChooseUs($page);
        if (! in_array($provider, [self::GOOGLE, self::MIODOTTORE], true)) {
            throw ValidationException::withMessages(['provider' => 'Provider recensione non valido.']);
        }
        if ($review !== null && ($review->page_id !== $page->id || $review->provider !== $provider)) {
            throw ValidationException::withMessages(['review_id' => 'La recensione selezionata non appartiene a questa pagina o provider.']);
        }
        PageFeaturedReview::query()->updateOrCreate(
            ['page_id' => $page->id, 'provider' => $provider],
            ['page_review_id' => $review?->id],
        );
    }
}
