<?php

namespace App\Services;

use App\Models\SiteNavigation;
use App\Support\Navigation\SiteNavigationRegistry;

class SiteNavigationInitializer
{
    public function initialize(): SiteNavigation
    {
        return SiteNavigation::query()->firstOrCreate(['id' => 1], ['configuration' => SiteNavigationRegistry::defaults()]);
    }
}
