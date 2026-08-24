<?php

namespace App\Services;

use App\Models\Checkup;
use App\Models\Service;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckupCatalogService
{
    public function create(array $payload): Checkup
    {
        return DB::transaction(function () use ($payload): Checkup {
            $checkup = Checkup::query()->create($this->attributes($payload));
            $this->syncItems($checkup, $payload['items'] ?? []);

            return $checkup;
        });
    }

    public function update(Checkup $checkup, array $payload): Checkup
    {
        return DB::transaction(function () use ($checkup, $payload): Checkup {
            $checkup->fill($this->attributes($payload));
            $checkup->save();
            $this->syncItems($checkup, $payload['items'] ?? []);

            return $checkup;
        });
    }

    public function delete(Checkup $checkup): void
    {
        DB::transaction(fn () => $checkup->delete());
    }

    public function loadForResource(Checkup $checkup, bool $includeProfessionals = false): Checkup
    {
        $relations = [
            'items.service.specializations',
            'items.service.professionalServices' => fn (Relation $query) => $query
                ->where('is_active', true)
                ->whereHas('professional', fn ($professionalQuery) => $professionalQuery->where('is_active', true)),
        ];

        if ($includeProfessionals) {
            $relations['items.service.professionalServices.professional'] = fn (Relation $query) => $query->where('is_active', true);
        }

        return $checkup->load($relations);
    }

    private function attributes(array $payload): array
    {
        return [
            'display_name' => trim((string) $payload['display_name']),
            'price_amount' => $payload['price_amount'],
            'indicative_duration_minutes' => $payload['indicative_duration_minutes'],
            'is_active' => $payload['is_active'] ?? true,
            'organizational_notes' => $payload['organizational_notes'] ?? null,
        ];
    }

    private function syncItems(Checkup $checkup, array $items): void
    {
        $serviceIds = collect($items)
            ->pluck('service_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($serviceIds->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Il Check-up deve contenere almeno una prestazione.',
            ]);
        }

        if ($serviceIds->count() !== $serviceIds->unique()->count()) {
            throw ValidationException::withMessages([
                'items' => 'Ogni prestazione puo essere inserita una sola volta.',
            ]);
        }

        $services = Service::query()
            ->whereIn('id', $serviceIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($services->count() !== $serviceIds->unique()->count()) {
            throw ValidationException::withMessages([
                'items' => 'Una o piu prestazioni selezionate non sono disponibili.',
            ]);
        }

        if ($checkup->is_active) {
            $inactiveNames = $serviceIds
                ->map(fn (int $serviceId): ?Service => $services->get($serviceId))
                ->filter(fn (?Service $service): bool => $service !== null && ! $service->is_active)
                ->map(fn (Service $service): string => $service->display_name)
                ->values();

            if ($inactiveNames->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Un Check-up attivo puo contenere solo prestazioni attive: '.$inactiveNames->implode(', ').'.',
                ]);
            }
        }

        $checkup->items()->delete();
        $checkup->items()->createMany($serviceIds
            ->values()
            ->map(fn (int $serviceId, int $index): array => [
                'service_id' => $serviceId,
                'sort_order' => $index,
            ])
            ->all());
    }
}
