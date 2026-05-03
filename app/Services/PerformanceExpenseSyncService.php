<?php

namespace App\Services;

use App\Enums\PerformanceSplitMode;
use App\Enums\PerformanceSplitSubjectType;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\PerformanceRecord;
use App\Models\PerformanceRecordSplit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PerformanceExpenseSyncService
{
    private const PROFESSIONALS_CATEGORY_NAME = 'Professionisti';

    private const PROFESSIONALS_CATEGORY_SLUG = 'professionisti';

    private const DIRECT_COSTS_CATEGORY_NAME = 'Costi diretti prestazione';

    private const DIRECT_COSTS_CATEGORY_SLUG = 'costi-diretti-prestazione';

    public function __construct(
        private readonly PerformancePaymentStatusSyncService $paymentStatusSyncService,
    ) {
    }

    public function syncFromPerformanceRecord(PerformanceRecord $performanceRecord): ExpenseRecord
    {
        $performedAt = Carbon::parse($performanceRecord->performed_at);
        $paymentStatus = $this->paymentStatusSyncService->normalize($performanceRecord->payment_status);

        $chunks = $this->buildExpenseChunks($performanceRecord);
        $categoriesBySlug = collect($chunks)
            ->pluck('category_slug')
            ->unique()
            ->mapWithKeys(fn (string $slug): array => [$slug => $this->resolveAutomaticCategory($slug)]);
        $existing = ExpenseRecord::query()
            ->where('source_performance_record_id', $performanceRecord->id)
            ->get()
            ->keyBy(fn (ExpenseRecord $record) => $record->generation_key ?? 'legacy');

        $lastTouched = null;
        $keepKeys = [];

        foreach ($chunks as $chunk) {
            $key = $chunk['generation_key'];
            $keepKeys[] = $key;
            $current = $existing->get($key);

            $attributes = [
                'expense_category_id' => $categoriesBySlug->get((string) $chunk['category_slug'])?->id,
                'expense_template_id' => null,
                'source' => 'automatic',
                'generation_key' => $key,
                'source_performance_record_id' => $performanceRecord->id,
                'expense_date' => $performedAt->toDateString(),
                'competence_start_date' => $performedAt->copy()->startOfMonth()->toDateString(),
                'competence_end_date' => $performedAt->copy()->startOfMonth()->toDateString(),
                'competence_months_count' => 1,
                'competence_month' => (int) $performedAt->format('n'),
                'competence_year' => (int) $performedAt->format('Y'),
                'description' => Str::limit((string) $chunk['description'], 190, ''),
                'type' => 'variable',
                'amount' => $this->normalizeMoneyAmount($chunk['amount']),
                'supplier' => $chunk['supplier'],
                'notes' => $this->notesFor($performanceRecord, $chunk['notes_suffix']),
            ];

            if ($current) {
                $current->fill([
                    ...$attributes,
                    'payment_status' => $paymentStatus,
                ]);
                $current->save();
                $lastTouched = $current;
                continue;
            }

            $lastTouched = ExpenseRecord::query()->create([
                ...$attributes,
                'payment_status' => $paymentStatus,
            ]);
        }

        if ($existing->isNotEmpty()) {
            ExpenseRecord::query()
                ->where('source_performance_record_id', $performanceRecord->id)
                ->whereNotIn('generation_key', $keepKeys)
                ->delete();
        }

        if (! $lastTouched) {
            $fallbackCategory = $this->resolveAutomaticCategory(self::PROFESSIONALS_CATEGORY_SLUG);
            $lastTouched = ExpenseRecord::query()->create([
                'expense_category_id' => $fallbackCategory->id,
                'expense_template_id' => null,
                'source' => 'automatic',
                'generation_key' => 'performance:'.$performanceRecord->id.':fallback',
                'source_performance_record_id' => $performanceRecord->id,
                'expense_date' => $performedAt->toDateString(),
                'competence_start_date' => $performedAt->copy()->startOfMonth()->toDateString(),
                'competence_end_date' => $performedAt->copy()->startOfMonth()->toDateString(),
                'competence_months_count' => 1,
                'competence_month' => (int) $performedAt->format('n'),
                'competence_year' => (int) $performedAt->format('Y'),
                'description' => $this->descriptionFor($performanceRecord),
                'type' => 'variable',
                'amount' => $this->normalizeMoneyAmount($performanceRecord->professional_amount),
                'supplier' => $performanceRecord->professional_name_snapshot,
                'payment_status' => $paymentStatus,
                'notes' => $this->notesFor($performanceRecord),
            ]);
        }

        return $lastTouched->fresh(['category', 'template', 'competenceAllocations']);
    }

    public function deleteForPerformanceRecord(PerformanceRecord $performanceRecord): void
    {
        ExpenseRecord::query()
            ->where('source_performance_record_id', $performanceRecord->id)
            ->delete();
    }

    private function resolveAutomaticCategory(string $slug): ExpenseCategory
    {
        return match ($slug) {
            self::DIRECT_COSTS_CATEGORY_SLUG => ExpenseCategory::query()->firstOrCreate(
                ['slug' => self::DIRECT_COSTS_CATEGORY_SLUG],
                ['name' => self::DIRECT_COSTS_CATEGORY_NAME, 'is_active' => true],
            ),
            default => ExpenseCategory::query()->firstOrCreate(
                ['slug' => self::PROFESSIONALS_CATEGORY_SLUG],
                ['name' => self::PROFESSIONALS_CATEGORY_NAME, 'is_active' => true],
            ),
        };
    }

    private function buildExpenseChunks(PerformanceRecord $performanceRecord): array
    {
        $chunks = [];

        if (($performanceRecord->split_mode?->value ?? $performanceRecord->split_mode) !== PerformanceSplitMode::Advanced->value) {
            $chunks[] = [
                'category_slug' => self::PROFESSIONALS_CATEGORY_SLUG,
                'generation_key' => 'performance:'.$performanceRecord->id.':standard',
                'description' => $this->professionalDescriptionFor($performanceRecord),
                'supplier' => $performanceRecord->professional_name_snapshot,
                'amount' => $performanceRecord->professional_amount,
                'notes_suffix' => null,
            ];

            return $this->appendDirectCostChunk($chunks, $performanceRecord);
        }

        /** @var Collection<int, PerformanceRecordSplit> $splits */
        $splits = $performanceRecord->relationLoaded('splits')
            ? $performanceRecord->splits
            : $performanceRecord->splits()->with('professional')->get();

        $professionalSplits = $splits
            ->filter(fn (PerformanceRecordSplit $split) => ($split->subject_type?->value ?? $split->subject_type) === PerformanceSplitSubjectType::Professional->value)
            ->values();

        if ($professionalSplits->isEmpty()) {
            $chunks[] = [
                'category_slug' => self::PROFESSIONALS_CATEGORY_SLUG,
                'generation_key' => 'performance:'.$performanceRecord->id.':standard',
                'description' => $this->professionalDescriptionFor($performanceRecord),
                'supplier' => $performanceRecord->professional_name_snapshot,
                'amount' => $performanceRecord->professional_amount,
                'notes_suffix' => null,
            ];

            return $this->appendDirectCostChunk($chunks, $performanceRecord);
        }

        $chunks = $professionalSplits
            ->map(function (PerformanceRecordSplit $split, int $index) use ($performanceRecord): array {
                $professionalId = $split->professional_id ?: 0;
                $professionalLabel = trim((string) ($split->professional_name_snapshot ?: $split->professional?->full_name ?: 'Professionista'));

                return [
                    'category_slug' => self::PROFESSIONALS_CATEGORY_SLUG,
                    'generation_key' => sprintf('performance:%d:professional:%d:%d', $performanceRecord->id, $professionalId, $index),
                    'description' => $this->professionalDescriptionFor($performanceRecord, $professionalLabel),
                    'supplier' => $professionalLabel,
                    'amount' => $split->amount,
                    'notes_suffix' => ' Ripartizione avanzata: quota assegnata a '.$professionalLabel.'.',
                ];
            })
            ->all();

        return $this->appendDirectCostChunk($chunks, $performanceRecord);
    }

    private function appendDirectCostChunk(array $chunks, PerformanceRecord $performanceRecord): array
    {
        if ((float) $performanceRecord->direct_cost <= 0) {
            return $chunks;
        }

        $chunks[] = [
            'category_slug' => self::DIRECT_COSTS_CATEGORY_SLUG,
            'generation_key' => 'performance:'.$performanceRecord->id.':direct-cost',
            'description' => $this->directCostDescriptionFor($performanceRecord),
            'supplier' => null,
            'amount' => $performanceRecord->direct_cost,
            'notes_suffix' => ' Costo diretto prestazione.',
        ];

        return $chunks;
    }

    private function professionalDescriptionFor(PerformanceRecord $performanceRecord, ?string $subjectLabel = null): string
    {
        $serviceName = trim((string) $performanceRecord->service_name_snapshot);
        $base = $serviceName !== ''
            ? 'Costo professionista - '.$serviceName
            : 'Costo professionista';

        if ($subjectLabel !== null && trim($subjectLabel) !== '') {
            $base .= ' ('.trim($subjectLabel).')';
        }

        return Str::limit($base, 190, '');
    }

    private function directCostDescriptionFor(PerformanceRecord $performanceRecord): string
    {
        $serviceName = trim((string) $performanceRecord->service_name_snapshot);
        $base = $serviceName !== ''
            ? 'Costo diretto prestazione - '.$serviceName
            : 'Costo diretto prestazione';

        return Str::limit($base, 190, '');
    }

    private function notesFor(PerformanceRecord $performanceRecord, ?string $suffix = null): string
    {
        return sprintf(
            'Costo variabile generato automaticamente dalla prestazione effettuata #%d.%s',
            $performanceRecord->id,
            $suffix ? ' '.$suffix : '',
        );
    }

    private function normalizeMoneyAmount(mixed $raw): string
    {
        $normalized = is_string($raw)
            ? str_replace(',', '.', trim($raw))
            : $raw;

        $parsed = (float) $normalized;

        return number_format(max(0.01, $parsed), 2, '.', '');
    }
}
