<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\PageReviewService;
use Illuminate\Console\Command;
use Throwable;

class SyncGooglePageReviews extends Command
{
    protected $signature = 'page-reviews:sync-google';

    protected $description = 'Synchronize Google reviews for the Why Choose Us page.';

    public function handle(PageReviewService $reviews): int
    {
        $page = Page::query()->where('internal_key', Page::WHY_CHOOSE_US_INTERNAL_KEY)->first();
        if ($page === null) {
            $this->warn('Why Choose Us page not found.');

            return self::SUCCESS;
        }
        try {
            $count = count($reviews->syncGoogle($page));
            $this->info("Google reviews synchronized: {$count}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Google reviews synchronization failed.');

            return self::FAILURE;
        }
    }
}
