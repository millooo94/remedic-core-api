<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Models\DailyBookingStat;
use App\Models\InternalNotification;
use App\Models\User;
use App\Notifications\InternalNotificationAction;
use App\Notifications\InternalNotificationPayload;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class DailyBookingStatsService
{
    private const TIMEZONE = 'Europe/Rome';

    /** @param array{date:string,bookings_count:int,cancellations_count:int} $attributes */
    public function create(User $user, array $attributes): DailyBookingStat
    {
        return DailyBookingStat::query()->create([
            ...$attributes,
            'submitted_by' => $user->id,
            'submitted_at' => now(self::TIMEZONE),
        ])->load('submitter');
    }

    /** @param array{date:string,bookings_count:int,cancellations_count:int} $attributes */
    public function update(User $user, string $date, array $attributes): DailyBookingStat
    {
        $stat = DailyBookingStat::query()->whereDate('date', $date)->first();

        if ($stat === null) {
            throw (new ModelNotFoundException)->setModel(DailyBookingStat::class, [$date]);
        }

        $stat->update([
            ...$attributes,
            'date' => $date,
            'submitted_by' => $user->id,
            'submitted_at' => now(self::TIMEZONE),
        ]);

        return $stat->fresh('submitter');
    }

    public function find(string $date): DailyBookingStat
    {
        return DailyBookingStat::query()->with('submitter')->whereDate('date', $date)->firstOrFail();
    }

    public function history(int $perPage): LengthAwarePaginator
    {
        return DailyBookingStat::query()
            ->with('submitter')
            ->orderByDesc('date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return array<string, mixed> */
    public function summary(string $periodStart, string $periodEnd): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $currentStart = $today->subDays(6);
        $previousStart = $today->subDays(13);
        $previousEnd = $today->subDays(7);
        $chartStart = $today->subDays(29);

        $todayStat = DailyBookingStat::query()->whereDate('date', $today)->first();
        $currentWeek = $this->recordsBetween($currentStart, $today);
        $previousWeek = $this->recordsBetween($previousStart, $previousEnd);
        $period = DailyBookingStat::query()
            ->whereDate('date', '>=', $periodStart)
            ->whereDate('date', '<=', $periodEnd)
            ->selectRaw('COUNT(*) as recorded_days, COALESCE(SUM(bookings_count), 0) as bookings_count, COALESCE(SUM(cancellations_count), 0) as cancellations_count')
            ->first();
        $chartRecords = $this->recordsBetween($chartStart, $today)->keyBy(fn (DailyBookingStat $stat): string => $stat->date->toDateString());

        $currentAverage = $this->average($currentWeek);
        $previousAverage = $this->average($previousWeek);

        return [
            'today' => [
                'date' => $today->toDateString(),
                'bookings_count' => $todayStat?->bookings_count,
                'cancellations_count' => $todayStat?->cancellations_count,
                'is_submitted' => $todayStat !== null,
            ],
            'last_7_days' => [
                'average_bookings' => $currentAverage,
                'recorded_days' => $currentWeek->count(),
                'previous_average_bookings' => $previousAverage,
                'previous_recorded_days' => $previousWeek->count(),
                'variation_percent' => $this->variation($currentAverage, $previousAverage),
            ],
            'period' => [
                'start_date' => $periodStart,
                'end_date' => $periodEnd,
                'recorded_days' => (int) $period->recorded_days,
                'bookings_count' => (int) $period->bookings_count,
                'cancellations_count' => (int) $period->cancellations_count,
            ],
            'chart' => $this->chart($chartStart, $today, $chartRecords),
        ];
    }

    /** @return array{date:string,notification_public_id:string}|null */
    public function pendingFor(User $user): ?array
    {
        $notifications = InternalNotification::query()
            ->where('recipient_user_id', $user->id)
            ->where('kind', 'daily_booking_stats_required')
            ->orderBy('created_at')
            ->get(['public_id', 'source_public_id']);
        $dates = $notifications->pluck('source_public_id')->filter(fn (mixed $date): bool => is_string($date))->unique()->values();
        $submittedDates = DailyBookingStat::query()->get(['date'])->pluck('date')->map(fn ($date): string => $date->toDateString())->all();

        $pending = $notifications->first(fn (InternalNotification $notification): bool => ! in_array($notification->source_public_id, $submittedDates, true));

        return $pending === null ? null : [
            'date' => $pending->source_public_id,
            'notification_public_id' => $pending->public_id,
        ];
    }

    public function sendReminder(): int
    {
        $date = CarbonImmutable::now(self::TIMEZONE)->toDateString();

        if (DailyBookingStat::query()->whereDate('date', $date)->exists()) {
            return 0;
        }

        return app(InternalNotificationService::class)->notifyUsersWithPermission(
            AdminPermission::VIEW_BACKOFFICE->value,
            new InternalNotificationPayload(
                kind: 'daily_booking_stats_required',
                context: 'dashboard',
                title: 'Inserisci le prenotazioni di oggi',
                message: 'Registra prenotazioni e disdette ricevute il '.$date.'.',
                action: new InternalNotificationAction('dashboard', ['daily_booking_date' => $date]),
                sourceType: 'daily_booking_stat',
                sourcePublicId: $date,
                deduplicationKey: 'daily_booking_stats:'.$date,
            ),
        )->count();
    }

    /** @return Collection<int, DailyBookingStat> */
    private function recordsBetween(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return DailyBookingStat::query()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get();
    }

    /** @param Collection<int, DailyBookingStat> $records */
    private function average(Collection $records): ?float
    {
        return $records->isEmpty() ? null : round((float) $records->avg('bookings_count'), 2);
    }

    private function variation(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || $previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    /** @param Collection<string, DailyBookingStat> $records @return list<array{date:string,bookings_count:int|null,moving_average_7:float|null}> */
    private function chart(CarbonImmutable $start, CarbonImmutable $end, Collection $records): array
    {
        $points = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $window = $records->filter(fn (DailyBookingStat $stat): bool => $stat->date->betweenIncluded($date->subDays(6), $date));

            $points[] = [
                'date' => $key,
                'bookings_count' => $records->get($key)?->bookings_count,
                'moving_average_7' => $this->average($window),
            ];
        }

        return $points;
    }
}
