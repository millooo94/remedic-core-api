<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\PerformanceRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PerformanceExpenseSyncService
{
    private const PROFESSIONALS_CATEGORY_NAME = 'Professionisti';

    private const PROFESSIONALS_CATEGORY_SLUG = 'professionisti';

    public function syncFromPerformanceRecord(PerformanceRecord $performanceRecord): ExpenseRecord
    {
        $category = $this->resolveProfessionalsCategory();
        $performedAt = Carbon::parse($performanceRecord->performed_at);
        $existing = ExpenseRecord::query()
            ->where('source_performance_record_id', $performanceRecord->id)
            ->first();

        $attributes = [
            'expense_category_id' => $category->id,
            'expense_template_id' => null,
            'source' => 'automatic',
            'generation_key' => null,
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
            'notes' => $this->notesFor($performanceRecord),
        ];

        if ($existing) {
            $paymentStatus = $existing->payment_status?->value ?? $existing->payment_status ?? 'da_pagare';
            $existing->fill([
                ...$attributes,
                'payment_status' => $paymentStatus,
            ]);
            $existing->save();

            return $existing->fresh(['category', 'template', 'competenceAllocations']);
        }

        return ExpenseRecord::query()->create([
            ...$attributes,
            'payment_status' => 'da_pagare',
        ])->fresh(['category', 'template', 'competenceAllocations']);
    }

    public function deleteForPerformanceRecord(PerformanceRecord $performanceRecord): void
    {
        ExpenseRecord::query()
            ->where('source_performance_record_id', $performanceRecord->id)
            ->delete();
    }

    private function resolveProfessionalsCategory(): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            ['slug' => self::PROFESSIONALS_CATEGORY_SLUG],
            ['name' => self::PROFESSIONALS_CATEGORY_NAME, 'is_active' => true],
        );
    }

    private function descriptionFor(PerformanceRecord $performanceRecord): string
    {
        $serviceName = trim((string) $performanceRecord->service_name_snapshot);
        $base = $serviceName !== ''
            ? 'Costo professionista - '.$serviceName
            : 'Costo professionista';

        return Str::limit($base, 190, '');
    }

    private function notesFor(PerformanceRecord $performanceRecord): string
    {
        return sprintf(
            'Costo variabile generato automaticamente dalla prestazione effettuata #%d.',
            $performanceRecord->id,
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
