<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SitePopup extends Model
{
    protected $fillable = [
        'is_active', 'start_at', 'end_at', 'eyebrow', 'title', 'body', 'image_path',
        'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target',
        'campaign_version',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'start_at' => 'datetime', 'end_at' => 'datetime', 'campaign_version' => 'integer'];
    }

    public function status(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->start_at?->isFuture()) {
            return 'scheduled';
        }
        if ($this->end_at?->lte(now())) {
            return 'expired';
        }

        return 'active';
    }

    public function isEligible(): bool
    {
        return $this->status() === 'active';
    }
}
