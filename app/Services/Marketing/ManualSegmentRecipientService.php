<?php

namespace App\Services\Marketing;

use App\Models\MarketingSegment;
use App\Models\Patient;
use Illuminate\Support\Collection;

class ManualSegmentRecipientService
{
    public function __construct(
        private readonly MarketingContactNormalizer $contactNormalizer,
    ) {
    }

    /**
     * @param  array<int, string>  $values
     * @return array{
     *   valid: array<int, array{original_value:string, normalized_phone:string, patient_id:int|null, patient_name:string|null}>,
     *   invalid: array<int, array{value:string, reason:string}>
     * }
     */
    public function parse(array $values): array
    {
        $patientsByPhone = $this->patientsByNormalizedPhone();
        $valid = [];
        $invalid = [];
        $seen = [];

        foreach ($this->tokenize($values) as $value) {
            $normalized = $this->contactNormalizer->normalizePhone($value);
            if (! $normalized || ! $this->isPlausiblePhone($normalized)) {
                $invalid[] = [
                    'value' => $value,
                    'reason' => 'Numero non valido.',
                ];

                continue;
            }

            if (isset($seen[$normalized])) {
                $invalid[] = [
                    'value' => $value,
                    'reason' => 'Duplicato gia presente nel segmento.',
                ];

                continue;
            }

            $seen[$normalized] = true;
            /** @var Patient|null $patient */
            $patient = $patientsByPhone->get($normalized);

            $valid[] = [
                'original_value' => $value,
                'normalized_phone' => $normalized,
                'patient_id' => $patient?->id,
                'patient_name' => $patient?->full_name,
            ];
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
        ];
    }

    /**
     * @param  array<int, string>  $values
     */
    public function syncSegmentRecipients(MarketingSegment $segment, array $values): array
    {
        $parsed = $this->parse($values);

        $segment->manualRecipients()->delete();

        $payload = collect($parsed['valid'])
            ->values()
            ->map(fn (array $recipient, int $index) => [
                'patient_id' => $recipient['patient_id'],
                'original_value' => $recipient['original_value'],
                'normalized_phone' => $recipient['normalized_phone'],
                'sort_order' => $index,
            ])
            ->all();

        if ($payload !== []) {
            $segment->manualRecipients()->createMany($payload);
        }

        return $parsed;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function tokenize(array $values): array
    {
        return collect($values)
            ->flatMap(function ($value): array {
                if (! is_string($value)) {
                    return [];
                }

                return preg_split('/[\r\n,;]+/', $value) ?: [];
            })
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, Patient>
     */
    private function patientsByNormalizedPhone(): Collection
    {
        return Patient::query()
            ->select(['id', 'full_name', 'phone'])
            ->whereNotNull('phone')
            ->where('phone', '<>', '')
            ->get()
            ->mapWithKeys(function (Patient $patient): array {
                $normalized = $this->contactNormalizer->normalizePhone($patient->phone);

                return $normalized ? [$normalized => $patient] : [];
            });
    }

    private function isPlausiblePhone(string $normalized): bool
    {
        $digits = preg_replace('/\D+/', '', $normalized) ?? '';

        return strlen($digits) >= 8 && strlen($digits) <= 15;
    }
}
