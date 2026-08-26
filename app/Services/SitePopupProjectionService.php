<?php

namespace App\Services;

use App\Models\SitePopup;
use App\Support\Media\PublicMediaUrl;
use App\Support\Navigation\SiteNavigationRegistry;
use Illuminate\Http\Request;

class SitePopupProjectionService
{
    public function __construct(private readonly SiteNavigationProjectionService $navigation) {}

    /** @return array<string, mixed> */
    public function admin(SitePopup $popup, Request $request): array
    {
        return [
            'is_active' => $popup->is_active,
            'start_at' => $popup->start_at?->toIso8601String(),
            'end_at' => $popup->end_at?->toIso8601String(),
            'eyebrow' => $popup->eyebrow,
            'title' => $popup->title,
            'body' => $popup->body,
            'image_url' => PublicMediaUrl::fromPublicDisk($popup->image_path, $request),
            'primary_cta' => $this->adminCta($popup->primary_cta_label, $popup->primary_cta_target),
            'secondary_cta' => $this->adminCta($popup->secondary_cta_label, $popup->secondary_cta_target),
            'campaign_version' => $popup->campaign_version,
            'status' => $popup->status(),
            'targets' => $this->targets(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function public(SitePopup $popup, Request $request): ?array
    {
        if (! $popup->isEligible()) {
            return null;
        }

        return [
            'campaign_version' => $popup->campaign_version,
            'eyebrow' => $popup->eyebrow,
            'title' => $popup->title,
            'body' => $popup->body,
            'image_url' => PublicMediaUrl::fromPublicDisk($popup->image_path, $request),
            'primary_cta' => $this->publicCta($popup->primary_cta_label, $popup->primary_cta_target),
            'secondary_cta' => $this->publicCta($popup->secondary_cta_label, $popup->secondary_cta_target),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function targets(): array
    {
        return collect(SiteNavigationRegistry::TARGETS)->map(function (string $label, string $key): array {
            $target = $this->navigation->target($key);

            return ['key' => $key, 'label' => $label, ...$target];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function adminCta(?string $label, ?string $target): ?array
    {
        if ($label === null || $target === null) {
            return null;
        }

        return ['label' => $label, 'target' => $target, ...$this->navigation->target($target)];
    }

    /** @return array<string, mixed>|null */
    private function publicCta(?string $label, ?string $target): ?array
    {
        if ($label === null || $target === null) {
            return null;
        }
        $resolved = $this->navigation->target($target);
        if ($resolved['publication_state'] === 'action') {
            return ['label' => $label, 'action' => $target];
        }
        if ($resolved['href'] === null) {
            return null;
        }

        return ['label' => $label, 'href' => $resolved['href']];
    }
}
