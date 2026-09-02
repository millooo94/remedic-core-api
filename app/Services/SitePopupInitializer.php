<?php

namespace App\Services;

use App\Models\SitePopup;

class SitePopupInitializer
{
    public function initialize(): SitePopup
    {
        return SitePopup::query()->firstOrCreate(['id' => 1], ['is_active' => true, 'source_type' => 'manual', 'start_at' => now(), 'campaign_version' => 1]);
    }
}
