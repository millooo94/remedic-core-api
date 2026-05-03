<?php

namespace App\Services;

use App\Models\MarketingSegment;
use App\Models\Patient;
use App\Models\User;
use App\Services\Marketing\ManualSegmentRecipientService;
use App\Services\Marketing\PatientSegmentQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketingSegmentService
{
    public function __construct(
        private readonly PatientSegmentQueryService $segmentQueryService,
        private readonly ManualSegmentRecipientService $manualSegmentRecipientService,
    ) {
    }

    public function baseQuery(array $filters = []): Builder
    {
        return MarketingSegment::query()
            ->with(['creator', 'updater'])
            ->withCount('manualRecipients')
            ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    public function create(array $payload, User $actor): MarketingSegment
    {
        return DB::transaction(function () use ($payload, $actor): MarketingSegment {
            $segmentType = $this->segmentType($payload['segment_type'] ?? null);
            $preview = $this->previewForPayload($payload);

            $segment = MarketingSegment::query()->create([
                'name' => trim((string) $payload['name']),
                'description' => $this->nullableTrimmedString($payload['description'] ?? null),
                'segment_type' => $segmentType,
                'filters' => $segmentType === 'filter_based' ? ($payload['filters'] ?? []) : [],
                'last_preview_count' => $preview['count'],
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($segmentType === 'manual') {
                $this->manualSegmentRecipientService->syncSegmentRecipients($segment, $payload['manual_numbers'] ?? []);
            }

            return $segment->refresh()->load(['creator', 'updater', 'manualRecipients.patient'])->loadCount('manualRecipients');
        });
    }

    public function update(MarketingSegment $segment, array $payload, User $actor): MarketingSegment
    {
        return DB::transaction(function () use ($segment, $payload, $actor): MarketingSegment {
            $segmentType = $this->segmentType($payload['segment_type'] ?? null);
            $preview = $this->previewForPayload($payload);

            $segment->fill([
                'name' => trim((string) $payload['name']),
                'description' => $this->nullableTrimmedString($payload['description'] ?? null),
                'segment_type' => $segmentType,
                'filters' => $segmentType === 'filter_based' ? ($payload['filters'] ?? []) : [],
                'last_preview_count' => $preview['count'],
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'updated_by' => $actor->id,
            ]);
            $segment->save();

            if ($segmentType === 'manual') {
                $this->manualSegmentRecipientService->syncSegmentRecipients($segment, $payload['manual_numbers'] ?? []);
            } else {
                $segment->manualRecipients()->delete();
            }

            return $segment->refresh()->load(['creator', 'updater', 'manualRecipients.patient'])->loadCount('manualRecipients');
        });
    }

    public function delete(MarketingSegment $segment): void
    {
        $segment->delete();
    }

    public function previewCount(array $rules): int
    {
        return $this->segmentQueryService
            ->applySegmentFilters(Patient::query(), $rules)
            ->count();
    }

    /**
     * @param  array{segment_type?:string|null,filters?:array<int, array<string, mixed>>,manual_numbers?:array<int, string>}  $payload
     * @return array{count:int, invalid:array<int, array{value:string, reason:string}>}
     */
    public function previewForPayload(array $payload): array
    {
        $segmentType = $this->segmentType($payload['segment_type'] ?? null);

        if ($segmentType === 'manual') {
            $parsed = $this->manualSegmentRecipientService->parse($payload['manual_numbers'] ?? []);

            if ($parsed['valid'] === []) {
                throw ValidationException::withMessages([
                    'manual_numbers' => ['Inserisci almeno un numero valido per il segmento manuale.'],
                ]);
            }

            return [
                'count' => count($parsed['valid']),
                'invalid' => $parsed['invalid'],
            ];
        }

        $rules = $payload['filters'] ?? [];
        if ($rules === []) {
            throw ValidationException::withMessages([
                'filters' => ['Aggiungi almeno un filtro valido al segmento.'],
            ]);
        }

        return [
            'count' => $this->previewCount($rules),
            'invalid' => [],
        ];
    }

    public function nullableTrimmedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function segmentType(?string $value): string
    {
        return $value === 'manual' ? 'manual' : 'filter_based';
    }
}
