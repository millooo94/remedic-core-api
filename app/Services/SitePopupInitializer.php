<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use App\Models\SitePopup;

class SitePopupInitializer
{
    public function initialize(): SitePopup
    {
        $popup = SitePopup::query()->firstOrCreate(['id' => 1], ['is_active' => true, 'source_type' => 'manual', 'start_at' => now(), 'campaign_version' => 1]);
        $values = $popup->only(['eyebrow', 'title', 'body', 'primary_cta_label', 'secondary_cta_label']);
        $revision = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
        $popup->translations()->firstOrCreate(['locale' => SupportedLocale::IT->value], [
            ...$values,
            'source_revision' => $revision,
            'reviewed_source_revision' => $revision,
        ]);

        return $popup;
    }
}
