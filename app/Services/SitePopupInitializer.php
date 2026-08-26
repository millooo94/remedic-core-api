<?php

namespace App\Services;

use App\Models\SitePopup;

class SitePopupInitializer
{
    public function initialize(): SitePopup
    {
        return SitePopup::query()->firstOrCreate(['id' => 1], ['is_active' => false, 'source_type' => 'manual', 'campaign_version' => 1]);
    }
}
