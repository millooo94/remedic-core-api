<?php

namespace App\Services;

use App\Models\Page;
use App\Support\Media\PublicMediaUrl;
use App\Support\Pages\PageSectionRegistry;
use Illuminate\Http\Request;

/** Shared Contact-page hero media projection for every public contact surface. */
class ContactPageMediaResolver
{
    /** @return array{url:?string,alt:?string} */
    public function resolve(Request $request): array
    {
        $section = Page::query()
            ->where('internal_key', PageSectionRegistry::CONTACT_INTERNAL_KEY)
            ->first()?->sections()
            ->where('key', 'hero')
            ->first();
        $extra = $section?->extra_json ?? [];

        return [
            'url' => PublicMediaUrl::fromPublicDisk($extra['image_path'] ?? null, $request),
            'alt' => is_string($extra['image_alt'] ?? null) ? $extra['image_alt'] : null,
        ];
    }
}
