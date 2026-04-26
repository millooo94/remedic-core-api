<?php

namespace App\Services;

use App\Models\ExpenseRecord;
use App\Models\ExpenseTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AutomaticExpenseGenerationService
{
    public function generateDue(Carbon|string|null $referenceDate = null): array
    {
        $today = $referenceDate instanceof Carbon
            ? $referenceDate->copy()->startOfDay()
            : Carbon::parse($referenceDate ?? now())->startOfDay();

        $generated = 0;
        $skipped = 0;

        /** @var Collection<int, ExpenseTemplate> $templates */
        $templates = ExpenseTemplate::query()
            ->where('is_active', true)
            ->whereNotIn('recurrence', ['manual'])
            ->where(function ($query) use ($today): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today->toDateString());
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today->toDateString());
            })
            ->get();

        foreach ($templates as $template) {
            $dates = $this->dueDatesUntil($template, $today);
            foreach ($dates as $occurrenceDate) {
                $generationKey = $this->generationKey($template, $occurrenceDate);
                if (ExpenseRecord::query()->where('generation_key', $generationKey)->exists()) {
                    $skipped++;
                    continue;
                }

                ExpenseRecord::query()->create([
                    'expense_category_id' => $template->category_id,
                    'expense_template_id' => $template->id,
                    'source' => 'automatic',
                    'generation_key' => $generationKey,
                    'expense_date' => $occurrenceDate->toDateString(),
                    'competence_start_date' => $occurrenceDate->copy()->startOfMonth()->toDateString(),
                    'competence_end_date' => $occurrenceDate->copy()->startOfMonth()->toDateString(),
                    'competence_months_count' => 1,
                    'competence_month' => (int) $occurrenceDate->format('n'),
                    'competence_year' => (int) $occurrenceDate->format('Y'),
                    'description' => $template->name,
                    'type' => 'fixed',
                    'amount' => $this->normalizeMoneyAmount($template->default_amount),
                    'payment_status' => 'pagata',
                    'notes' => $template->notes,
                ]);

                $generated++;
            }
        }

        return [
            'templates_checked' => $templates->count(),
            'generated' => $generated,
            'skipped_duplicates' => $skipped,
        ];
    }

    public function nextGenerationDate(ExpenseTemplate $template, Carbon|string|null $fromDate = null): ?Carbon
    {
        if (!$template->is_active || $this->recurrenceValue($template) === 'manual') {
            return null;
        }

        $from = $fromDate instanceof Carbon
            ? $fromDate->copy()->startOfDay()
            : Carbon::parse($fromDate ?? now())->startOfDay();

        $cursor = $this->firstOccurrenceAtOrAfter($template, $from);

        for ($guard = 0; $guard < 240 && $cursor; $guard++) {
            if ($template->end_date && $cursor->gt($template->end_date->copy()->startOfDay())) {
                return null;
            }

            $key = $this->generationKey($template, $cursor);
            $alreadyGenerated = ExpenseRecord::query()->where('generation_key', $key)->exists();
            if (!$alreadyGenerated) {
                return $cursor;
            }

            $cursor = $this->nextOccurrence($template, $cursor);
        }

        return null;
    }

    private function dueDatesUntil(ExpenseTemplate $template, Carbon $until): array
    {
        $dates = [];
        $cursor = $this->firstOccurrenceAtOrAfter($template, $this->effectiveStartDate($template));
        $endDate = $template->end_date?->copy()->startOfDay();

        for ($guard = 0; $guard < 500 && $cursor; $guard++) {
            if ($cursor->gt($until)) {
                break;
            }
            if ($endDate && $cursor->gt($endDate)) {
                break;
            }

            $dates[] = $cursor->copy();
            $cursor = $this->nextOccurrence($template, $cursor);
        }

        return $dates;
    }

    private function firstOccurrenceAtOrAfter(ExpenseTemplate $template, Carbon $from): ?Carbon
    {
        $start = $this->effectiveStartDate($template);
        $cursor = $start->copy();

        if ($template->recurrence->value === 'weekly') {
            $targetWeekDay = $this->weeklyDayOfGeneration($template, $start);
            while ($cursor->dayOfWeekIso !== $targetWeekDay) {
                $cursor->addDay();
            }
        } else {
            $targetDay = $this->dayOfGeneration($template, $start);
            $cursor = $this->withDayInMonth($cursor, $targetDay);
            if ($cursor->lt($start)) {
                $cursor = $this->nextOccurrence($template, $cursor);
            }
        }

        while ($cursor && $cursor->lt($from)) {
            $cursor = $this->nextOccurrence($template, $cursor);
        }

        return $cursor;
    }

    private function nextOccurrence(ExpenseTemplate $template, Carbon $current): ?Carbon
    {
        return match ($this->recurrenceValue($template)) {
            'weekly' => $current->copy()->addWeek(),
            'monthly' => $this->withDayInMonth($current->copy()->addMonthNoOverflow(), $this->dayOfGeneration($template, $current)),
            'bimonthly' => $this->withDayInMonth($current->copy()->addMonthsNoOverflow(2), $this->dayOfGeneration($template, $current)),
            'quarterly' => $this->withDayInMonth($current->copy()->addMonthsNoOverflow(3), $this->dayOfGeneration($template, $current)),
            'yearly' => $this->withDayInMonth($current->copy()->addYearNoOverflow(), $this->dayOfGeneration($template, $current)),
            default => null,
        };
    }

    private function effectiveStartDate(ExpenseTemplate $template): Carbon
    {
        if ($template->start_date) {
            return $template->start_date->copy()->startOfDay();
        }

        return $template->created_at
            ? Carbon::parse($template->created_at)->startOfDay()
            : now()->startOfDay();
    }

    private function dayOfGeneration(ExpenseTemplate $template, Carbon $fallback): int
    {
        $day = (int) ($template->day_of_generation ?: $fallback->day);
        return max(1, min(31, $day));
    }

    private function weeklyDayOfGeneration(ExpenseTemplate $template, Carbon $fallback): int
    {
        $day = (int) ($template->day_of_generation ?: $fallback->dayOfWeekIso);
        return max(1, min(7, $day));
    }

    private function withDayInMonth(Carbon $value, int $targetDay): Carbon
    {
        $day = min($targetDay, $value->daysInMonth);
        return $value->copy()->day($day)->startOfDay();
    }

    private function generationKey(ExpenseTemplate $template, Carbon $date): string
    {
        return sprintf('expense-template:%d:%s', $template->id, $date->toDateString());
    }

    private function recurrenceValue(ExpenseTemplate $template): string
    {
        return is_string($template->recurrence)
            ? $template->recurrence
            : $template->recurrence->value;
    }

    private function normalizeMoneyAmount(mixed $value): string
    {
        $normalized = is_string($value)
            ? str_replace(',', '.', trim($value))
            : $value;

        $parsed = (float) $normalized;

        return number_format(max(0.01, $parsed), 2, '.', '');
    }
}
