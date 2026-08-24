<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\Redirect;

class BlogPostSlugRedirectService
{
    public function __construct(private readonly AutomaticSlugRedirectService $redirects) {}

    public function sync(BlogPost $post, string $previousSlug, string $currentSlug): void
    {
        $this->redirects->sync(
            Redirect::SOURCE_TYPE_BLOG_POST,
            $post->id,
            '/blog/'.$previousSlug,
            '/blog/'.$currentSlug,
        );
    }
}
