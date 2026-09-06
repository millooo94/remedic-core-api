<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Models\DailyBookingStat;
use App\Models\InternalNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DailyBookingStatsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    public function test_an_authorized_user_can_create_and_correct_a_daily_stat_without_duplicates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/daily-booking-stats', [
            'date' => '2026-09-04',
            'bookings_count' => 12,
            'cancellations_count' => 3,
        ])->assertCreated()->assertJsonPath('bookings_count', 12);

        $this->putJson('/api/v1/daily-booking-stats/2026-09-04', [
            'date' => '2026-09-04',
            'bookings_count' => 14,
            'cancellations_count' => 2,
        ])->assertOk()->assertJsonPath('cancellations_count', 2);

        $this->assertDatabaseCount('daily_booking_stats', 1);
        $stat = DailyBookingStat::query()->whereDate('date', '2026-09-04')->sole();
        $this->assertSame(14, $stat->bookings_count);
        $this->assertSame(2, $stat->cancellations_count);
        $this->assertSame($user->id, $stat->submitted_by);
    }

    public function test_create_rejects_duplicates_and_invalid_numeric_values(): void
    {
        $user = User::factory()->create();
        DailyBookingStat::query()->create(['date' => '2026-09-04', 'bookings_count' => 1, 'cancellations_count' => 0, 'submitted_by' => $user->id, 'submitted_at' => now()]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/daily-booking-stats', ['date' => '2026-09-04', 'bookings_count' => 1, 'cancellations_count' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson('/api/v1/daily-booking-stats', ['date' => '2026-09-05', 'bookings_count' => -1, 'cancellations_count' => 1.5])
            ->assertUnprocessable()->assertJsonValidationErrors(['bookings_count', 'cancellations_count']);
    }

    public function test_summary_uses_recorded_days_only_and_keeps_missing_chart_values_null(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04 10:00:00', 'Europe/Rome'));
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach ([['2026-09-04', 10], ['2026-09-02', 20], ['2026-08-27', 5]] as [$date, $bookings]) {
            DailyBookingStat::query()->create(['date' => $date, 'bookings_count' => $bookings, 'cancellations_count' => 0, 'submitted_by' => $user->id, 'submitted_at' => now()]);
        }

        $this->getJson('/api/v1/daily-booking-stats/summary?month=9&year=2026')
            ->assertOk()
            ->assertJsonPath('data.today.bookings_count', 10)
            ->assertJsonPath('data.last_7_days.average_bookings', 15)
            ->assertJsonPath('data.last_7_days.recorded_days', 2)
            ->assertJsonPath('data.period.bookings_count', 30)
            ->assertJsonPath('data.chart.28.date', '2026-09-03')
            ->assertJsonPath('data.chart.28.bookings_count', null);
    }

    public function test_history_is_descending_and_uses_the_global_pagination_contract(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach (['2026-09-01', '2026-09-03', '2026-09-02'] as $index => $date) {
            DailyBookingStat::query()->create(['date' => $date, 'bookings_count' => $index, 'cancellations_count' => 0, 'submitted_by' => $user->id, 'submitted_at' => now()]);
        }

        $this->getJson('/api/v1/daily-booking-stats?per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.date', '2026-09-03')
            ->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/v1/daily-booking-stats?per_page=12')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_daily_reminder_is_idempotent_and_targets_existing_backoffice_notification_users(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04 21:30:00', 'Europe/Rome'));
        $recipient = User::factory()->create();
        $recipient->givePermissionTo(AdminPermission::VIEW_BACKOFFICE->value);

        $this->artisan('daily-booking-stats:remind')->assertSuccessful();
        $this->artisan('daily-booking-stats:remind')->assertSuccessful();

        $notification = InternalNotification::query()->sole();
        $this->assertSame($recipient->id, $notification->recipient_user_id);
        $this->assertSame('daily_booking_stats_required', $notification->kind);
        $this->assertSame('2026-09-04', $notification->source_public_id);
        $this->assertSame(['route' => 'dashboard', 'params' => ['daily_booking_date' => '2026-09-04']], $notification->action);
    }
}
