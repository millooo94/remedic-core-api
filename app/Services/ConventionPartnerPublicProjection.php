<?php

namespace App\Services;

use App\Enums\ConventionPartnerType;
use App\Models\ConventionPartner;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;

class ConventionPartnerPublicProjection
{
    /** @return array{items: list<array{name: string, type: string, type_label: string, logo_url: ?string}>, available_types: list<array{type: string, label: string}>} */
    public function catalog(Request $request): array
    {
        $partners = ConventionPartner::query()->publiclyAvailable()->publicOrder()->get();

        return [
            'items' => $partners->map(fn (ConventionPartner $partner): array => [
                'name' => $partner->name,
                'type' => $partner->type->value,
                'type_label' => $partner->type->label(),
                'logo_url' => PublicMediaUrl::fromPublicDisk($partner->logo_path, $request),
            ])->values()->all(),
            'available_types' => collect(ConventionPartnerType::cases())->whereIn('value', $partners->map(fn (ConventionPartner $partner) => $partner->type->value)->unique()->all())
                ->map(fn (ConventionPartnerType $type): array => ['type' => $type->value, 'label' => $type->filterLabel()])
                ->values()
                ->all(),
        ];
    }
}
