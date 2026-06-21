<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Redirect;

class PageSlugRedirectService
{
    public function sync(Page $page, string $previousSlug, string $currentSlug): void
    {
        $oldPath = Redirect::normalizePathValue($previousSlug);
        $newPath = Redirect::normalizePathValue($currentSlug);

        if ($oldPath === $newPath) {
            return;
        }

        Redirect::query()
            ->automatic()
            ->where('source_type', Redirect::SOURCE_TYPE_PAGE)
            ->where('source_id', $page->id)
            ->where('from_path', $newPath)
            ->update(['is_active' => false]);

        Redirect::query()
            ->automatic()
            ->where('source_type', Redirect::SOURCE_TYPE_PAGE)
            ->where('source_id', $page->id)
            ->where('to_path', $oldPath)
            ->update([
                'to_path' => $newPath,
                'http_code' => 301,
                'is_active' => true,
            ]);

        Redirect::query()->updateOrCreate(
            ['from_path' => $oldPath],
            [
                'to_path' => $newPath,
                'http_code' => 301,
                'is_active' => true,
                'is_automatic' => true,
                'source_type' => Redirect::SOURCE_TYPE_PAGE,
                'source_id' => $page->id,
            ],
        );
    }
}
