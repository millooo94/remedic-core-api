<?php

namespace App\Services;

use App\Enums\SitePopupSourceType;
use App\Models\Event;
use App\Models\Promotion;
use App\Models\SitePopup;
use App\Models\SiteSetting;
use App\Support\Media\PublicMediaUrl;
use App\Support\Navigation\SiteNavigationRegistry;
use Illuminate\Http\Request;

class SitePopupProjectionService
{
    public function __construct(private readonly SiteNavigationProjectionService $navigation) {}

    /** @return array<string, mixed> */
    public function admin(SitePopup $popup, Request $request): array
    {
        $popup->loadMissing(['promotion.service', 'promotion.checkup.items', 'event']);

        return [
            'is_active' => $popup->is_active,
            'source_type' => $popup->source_type->value,
            'promotion_id' => $popup->promotion_id,
            'event_id' => $popup->event_id,
            'source' => $this->source($popup, $request),
            'source_is_effectively_available' => $this->sourceAvailable($popup),
            'start_at' => $popup->start_at?->toIso8601String(),
            'end_at' => $popup->end_at?->toIso8601String(),
            'eyebrow' => $popup->eyebrow,
            'title' => $popup->title,
            'body' => $popup->body,
            'image_path' => $popup->image_path,
            'image_url' => PublicMediaUrl::fromPublicDisk($popup->image_path, $request),
            'primary_cta' => $this->adminCta($popup->primary_cta_label, $popup->primary_cta_target),
            'secondary_cta' => $this->adminCta($popup->secondary_cta_label, $popup->secondary_cta_target),
            'campaign_version' => $popup->campaign_version,
            'status' => $popup->status(),
            'targets' => $this->targets(),
        ];
    }

    /** @return array<string, mixed> */
    public function lookups(SitePopup $popup, Request $request): array
    {
        return [
            'promotions' => Promotion::query()->with(['service', 'checkup.items'])->orderByDesc('start_at')->get()
                ->map(fn (Promotion $promotion): array => $this->promotionSummary($promotion, $request))->values()->all(),
            'events' => Event::query()->orderByDesc('start_at')->get()
                ->map(fn (Event $event): array => $this->eventSummary($event, $request))->values()->all(),
            'current_source' => $this->source($popup->loadMissing(['promotion.service', 'promotion.checkup.items', 'event']), $request),
        ];
    }

    /** @return array<string, mixed>|null */
    public function public(SitePopup $popup, Request $request): ?array
    {
        $popup->loadMissing(['promotion.service', 'promotion.checkup.items', 'event']);
        if (! $popup->isEligible() || ! $popup->hasValidSource()) {
            return null;
        }

        return [
            'campaign_version' => $popup->campaign_version,
            'eyebrow' => $popup->eyebrow,
            'title' => $popup->title,
            'body' => $popup->body,
            'image_url' => PublicMediaUrl::fromPublicDisk($popup->image_path, $request),
            'primary_cta' => $this->publicCta($popup->primary_cta_label, $popup->primary_cta_target, $request),
            'secondary_cta' => $this->publicCta($popup->secondary_cta_label, $popup->secondary_cta_target, $request),
            'source' => $this->publicSource($popup, $request),
        ];
    }

    /** @return array{type: string, data: array<string, mixed>|null} */
    private function source(SitePopup $popup, Request $request): array
    {
        return match ($popup->source_type) {
            SitePopupSourceType::MANUAL => ['type' => 'manual', 'data' => null],
            SitePopupSourceType::PROMOTION => ['type' => 'promotion', 'data' => $popup->promotion ? $this->promotionSummary($popup->promotion, $request) : null],
            SitePopupSourceType::EVENT => ['type' => 'event', 'data' => $popup->event ? $this->eventSummary($popup->event, $request) : null],
        };
    }

    /** Public source metadata intentionally excludes operational primary keys. */
    private function publicSource(SitePopup $popup, Request $request): array
    {
        return match ($popup->source_type) {
            SitePopupSourceType::MANUAL => ['type' => 'manual', 'data' => null],
            SitePopupSourceType::PROMOTION => ['type' => 'promotion', 'data' => $popup->promotion ? $this->publicPromotionSummary($popup->promotion, $request) : null],
            SitePopupSourceType::EVENT => ['type' => 'event', 'data' => $popup->event ? $this->publicEventSummary($popup->event, $request) : null],
        };
    }

    private function sourceAvailable(SitePopup $popup): bool
    {
        return match ($popup->source_type) {
            SitePopupSourceType::MANUAL => true,
            SitePopupSourceType::PROMOTION => $popup->promotion?->isEffectivelyAvailable() ?? false,
            SitePopupSourceType::EVENT => $popup->event?->isEffectivelyAvailable() ?? false,
        };
    }

    /** @return array<string, mixed> */
    private function promotionSummary(Promotion $promotion, ?Request $request = null): array
    {
        $promotion->loadMissing(['service', 'checkup.items']);
        $target = $promotion->service_id !== null ? $promotion->service : $promotion->checkup;
        $standardPrice = $promotion->service_id !== null ? $promotion->service?->importo_prestazione : $promotion->checkup?->price_amount;
        $standardPrice = $standardPrice === null ? null : (float) $standardPrice;
        $promotionalPrice = (float) $promotion->promotional_price;
        $saving = $standardPrice === null ? null : round($standardPrice - $promotionalPrice, 2);

        return [
            'id' => $promotion->id, 'name' => $promotion->name, 'image_path' => $promotion->image_path, 'image_url' => $request ? PublicMediaUrl::fromPublicDisk($promotion->image_path, $request) : null, 'target_type' => $promotion->targetType(), 'target_name' => $target?->display_name,
            'target_is_operational' => $promotion->targetIsOperational(), 'standard_price' => $standardPrice, 'promotional_price' => $promotion->promotional_price,
            'saving_amount' => $saving, 'discount_percentage' => $standardPrice !== null && $standardPrice > 0 ? round(($saving / $standardPrice) * 100, 2) : null,
            'start_at' => $promotion->start_at?->toIso8601String(), 'end_at' => $promotion->end_at?->toIso8601String(), 'validity_basis' => $promotion->validity_basis?->value,
            'lifecycle_status' => $promotion->lifecycleStatus(), 'is_effectively_available' => $promotion->isEffectivelyAvailable(), 'is_archived' => $promotion->trashed(),
        ];
    }

    /** @return array<string, mixed> */
    private function eventSummary(Event $event, ?Request $request = null): array
    {
        return [
            'id' => $event->id, 'name' => $event->name, 'image_path' => $event->image_path, 'image_url' => $request ? PublicMediaUrl::fromPublicDisk($event->image_path, $request) : null, 'event_type' => $event->event_type->value, 'operational_status' => $event->operational_status->value,
            'temporal_status' => $event->temporalStatus(), 'is_effectively_available' => $event->isEffectivelyAvailable(), 'start_at' => $event->start_at?->toIso8601String(), 'end_at' => $event->end_at?->toIso8601String(),
            'location_type' => $event->location_type->value, 'location_summary' => $this->locationSummary($event), 'registration_required' => $event->registration_required,
            'registration_mode' => $event->registration_mode->value, 'registration_deadline' => $event->registration_deadline?->toIso8601String(), 'is_registration_open' => $event->isRegistrationOpen(),
            'capacity' => $event->capacity, 'participation_price' => $event->participation_price, 'is_archived' => $event->trashed(),
        ];
    }

    private function publicPromotionSummary(Promotion $promotion, Request $request): array
    {
        $summary = $this->promotionSummary($promotion, $request);
        unset($summary['id']);

        return $summary;
    }

    private function publicEventSummary(Event $event, Request $request): array
    {
        $summary = $this->eventSummary($event, $request);
        unset($summary['id']);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function locationSummary(Event $event): array
    {
        if ($event->location_type->value === 'online') {
            return ['label' => 'Online', 'address' => null];
        }
        if ($event->location_type->value === 'external') {
            return ['label' => $event->external_venue_name ?: 'Sede esterna', 'address' => $event->external_venue_address];
        }

        $settings = SiteSetting::current();

        return ['label' => $settings->clinic_name ?: 'Remedic', 'address' => $settings->clinic_address];
    }

    /** @return list<array<string, mixed>> */
    private function targets(): array
    {
        return collect(SiteNavigationRegistry::TARGETS)->map(function (string $label, string $key): array {
            return ['key' => $key, 'label' => $label, ...$this->navigation->target($key)];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function adminCta(?string $label, ?string $target): ?array
    {
        return $label === null || $target === null ? null : ['label' => $label, 'target' => $target, ...$this->navigation->target($target)];
    }

    /** @return array<string, mixed>|null */
    private function publicCta(?string $label, ?string $target, Request $request): ?array
    {
        if ($label === null || $target === null) {
            return null;
        }
        $resolved = $this->navigation->target($target, app(PublicLocaleResolver::class)->resolve($request));
        if ($resolved['is_action']) {
            return array_filter(['label' => $label, 'action' => $resolved['action'] ?? $target, 'href' => $resolved['href']], static fn (mixed $value): bool => $value !== null);
        }

        return $resolved['href'] === null ? null : ['label' => $label, 'href' => $resolved['href']];
    }
}
