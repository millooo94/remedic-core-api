<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Marketing\PatientSegmentQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class PatientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PatientSegmentQueryService $segmentQueryService */
        $segmentQueryService = app(PatientSegmentQueryService::class);
        $availableChannels = $this->available_channels ?? $segmentQueryService->availableChannelMap($this->resource);
        $visitedSpecializations = is_array($this->visited_specializations ?? null)
            ? $this->visited_specializations
            : $segmentQueryService->specializationsFromRaw($this->visited_specializations_raw ?? null);
        $birthDate = $this->birth_date ? Carbon::parse((string) $this->birth_date) : null;
        $age = $birthDate ? $birthDate->age : null;
        $displayName = trim(implode(' ', array_filter([
            trim((string) $this->first_name),
            trim((string) $this->last_name),
        ])));

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $displayName !== '' ? $displayName : $this->full_name,
            'sex' => $this->sex,
            'birth_date' => $birthDate?->toDateString(),
            'age' => $age,
            'year_of_birth' => $this->year_of_birth,
            'tax_code' => $this->tax_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'residence_address' => $this->residence_address,
            'residence_city' => $this->residence_city,
            'residence_province' => $this->residence_province,
            'residence_zip' => $this->residence_zip,
            'residence_latitude' => $this->residence_latitude !== null ? (float) $this->residence_latitude : null,
            'residence_longitude' => $this->residence_longitude !== null ? (float) $this->residence_longitude : null,
            'geocoding_status' => $this->geocoding_status,
            'contactable_sms' => (bool) $this->contactable_sms,
            'contactable_whatsapp' => (bool) $this->contactable_whatsapp,
            'contactable_email' => (bool) $this->contactable_email,
            'excluded_from_campaigns' => (bool) $this->excluded_from_campaigns,
            'notes' => $this->notes,
            'available_channels' => $availableChannels,
            'performances_count' => isset($this->performances_count) ? (int) $this->performances_count : null,
            'last_visit_at' => $this->last_visit_at ? Carbon::parse((string) $this->last_visit_at)->toDateString() : null,
            'visited_specializations' => $visitedSpecializations,
            'specialization_summary' => $this->specialization_summary ?? [],
            'recent_performances' => $this->whenLoaded('performanceRecords', fn () => PerformanceRecordResource::collection($this->performanceRecords)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
