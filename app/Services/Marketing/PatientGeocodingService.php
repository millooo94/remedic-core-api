<?php

namespace App\Services\Marketing;

use App\Services\CenterCoordinatesProvider;
use Illuminate\Support\Facades\Http;

class PatientGeocodingService
{
    public function __construct(private readonly CenterCoordinatesProvider $centerCoordinates) {}

    public function geocode(?string $address, ?string $city, ?string $zip): array
    {
        $query = $this->normalizeAddress($address, $city, $zip);
        if (! $query) {
            return [
                'status' => 'missing_address',
                'lat' => null,
                'lng' => null,
            ];
        }

        try {
            $response = Http::timeout((int) config('services.geocoding.timeout_seconds', 8))
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => (string) config('services.geocoding.user_agent', 'remedic-core/1.0'),
                ])
                ->get((string) config('services.geocoding.provider_url'), [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                ]);

            if (! $response->ok()) {
                return [
                    'status' => 'provider_error',
                    'lat' => null,
                    'lng' => null,
                ];
            }

            $row = collect($response->json() ?? [])->first();
            $lat = is_array($row) ? $this->toFloat($row['lat'] ?? null) : null;
            $lng = is_array($row) ? $this->toFloat($row['lon'] ?? null) : null;

            if ($lat === null || $lng === null) {
                return [
                    'status' => 'not_found',
                    'lat' => null,
                    'lng' => null,
                ];
            }

            return [
                'status' => 'ok',
                'lat' => $lat,
                'lng' => $lng,
            ];
        } catch (\Throwable) {
            return [
                'status' => 'provider_error',
                'lat' => null,
                'lng' => null,
            ];
        }
    }

    public function remedicCoordinates(): array
    {
        return $this->centerCoordinates->coordinates();
    }

    private function normalizeAddress(?string $address, ?string $city, ?string $zip): ?string
    {
        $parts = collect([
            $this->nullableTrimmed($address),
            $this->nullableTrimmed($city),
            $this->nullableTrimmed($zip),
            'Italia',
        ])->filter()->values();

        if ($parts->count() <= 1) {
            return null;
        }

        return $parts->implode(', ');
    }

    private function nullableTrimmed(?string $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
