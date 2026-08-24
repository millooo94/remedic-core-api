<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Redirect;

class PageSlugRedirectService
{
    public function __construct(private readonly AutomaticSlugRedirectService $redirects) {}

    public function sync(Page $page, string $previousSlug, string $currentSlug): void
    {
        $this->redirects->sync(Redirect::SOURCE_TYPE_PAGE, $page->id, $previousSlug, $currentSlug);
    }
}
