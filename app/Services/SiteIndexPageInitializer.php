<?php

namespace App\Services;

use App\Models\SiteIndexPage;
use App\Support\SiteIndexes\SiteIndexPageRegistry;

class SiteIndexPageInitializer
{
    public function initialize(): void
    {
        foreach (SiteIndexPageRegistry::KEYS as $key) {
            $d = SiteIndexPageRegistry::defaults($key);
            SiteIndexPage::query()->firstOrCreate(['internal_key' => $key], $d + ['is_active' => true, 'published_at' => null]);
        }
    }
}
