<?php

namespace Tests\Feature;

use App\Models\ExternalProviderAccount;
use App\Models\ExternalProviderProfessional;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityException;
use App\Models\ProfessionalAvailabilityRule;
use App\Services\MiodottoreAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MiodottoreSyncAvailabilitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_runs_in_dry_run_mode_without_writing_and_preserves_manual_rows(): void
    {
        $professional = Professional::factory()->create(['full_name' => 'Sebastiano Arena']);

        ExternalProviderProfessional::query()->create([
            'professional_id' => $professional->id,
            'provider' => 'miodottore',
            'external_name' => 'Arena, Sebastiano',
            'external_id' => '293871',
            'enabled' => true,
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => null,
            'date' => '2026-06-19',
            'type' => 'available',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'reason' => 'Manuale',
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-29',
            'type' => 'available',
            'start_time' => '10:00',
            'end_time' => '11:00',
            'reason' => null,
            'external_hash' => 'old-exception-hash',
            'last_synced_at' => now()->subDay(),
        ]);

        ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'weekday' => 3,
            'start_time' => '08:30',
            'end_time' => '09:30',
            'is_active' => true,
            'external_hash' => 'old-rule-hash',
            'last_synced_at' => now()->subDay(),
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('debugMiodottoreAvailabilities')
                ->once()
                ->andReturn($this->fakeNormalizedSource(includeUnmapped: true));
        });

        $this->artisan('miodottore:sync-availabilities', ['--days' => 30])
            ->expectsOutputToContain('Accesso MioDottore: OK')
            ->expectsOutputToContain('Modalita: DRY-RUN')
            ->expectsOutputToContain('Professionisti mappati: 1')
            ->expectsOutputToContain('Professionisti non mappati: 1')
            ->expectsOutputToContain('Orari settimanali da importare: 2')
            ->expectsOutputToContain('Eccezioni positive da importare: 1')
            ->expectsOutputToContain('Blocchi/non disponibilita ignorati: 1')
            ->expectsOutputToContain('Regole MioDottore esistenti da sostituire: 1')
            ->expectsOutputToContain('Eccezioni MioDottore esistenti nel range: 1')
            ->expectsOutputToContain('Nessuna scrittura eseguita. Usa --write per confermare.')
            ->assertExitCode(0);

        $this->assertSame(1, ProfessionalAvailabilityException::query()->whereNull('source')->count());
        $this->assertSame(1, ProfessionalAvailabilityException::query()->where('source', 'miodottore')->count());
        $this->assertSame(1, ProfessionalAvailabilityRule::query()->where('source', 'miodottore')->count());
    }

    #[Test]
    public function it_writes_weekly_rules_and_positive_daily_exceptions_without_importing_unavailable_blocks(): void
    {
        $professional = Professional::factory()->create(['full_name' => 'Sebastiano Arena']);

        $mapping = ExternalProviderProfessional::query()->create([
            'professional_id' => $professional->id,
            'provider' => 'miodottore',
            'external_name' => 'Arena, Sebastiano',
            'external_id' => '293871',
            'enabled' => true,
            'sync_status' => 'never_synced',
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => null,
            'date' => '2026-06-19',
            'type' => 'available',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'reason' => 'Manuale',
        ]);

        ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => null,
            'weekday' => 1,
            'start_time' => '07:00',
            'end_time' => '08:00',
            'is_active' => true,
            'notes' => 'Regola manuale',
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-29',
            'type' => 'available',
            'start_time' => '15:30',
            'end_time' => '17:00',
            'reason' => null,
            'external_hash' => 'old-split-one',
            'last_synced_at' => now()->subDay(),
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-19',
            'type' => 'unavailable',
            'start_time' => '00:00',
            'end_time' => '23:59',
            'reason' => 'Parigi',
            'external_hash' => 'old-block',
            'last_synced_at' => now()->subDay(),
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-08-01',
            'type' => 'available',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'reason' => null,
            'external_hash' => 'outside-range',
            'last_synced_at' => now()->subDay(),
        ]);

        ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'weekday' => 5,
            'start_time' => '08:30',
            'end_time' => '09:30',
            'is_active' => true,
            'external_hash' => 'old-rule-hash',
            'last_synced_at' => now()->subDay(),
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('debugMiodottoreAvailabilities')
                ->once()
                ->andReturn($this->fakeNormalizedSource(includeUnmapped: false));
        });

        $this->artisan('miodottore:sync-availabilities', ['--days' => 30, '--write' => true])
            ->expectsOutputToContain('Accesso MioDottore: OK')
            ->expectsOutputToContain('Modalita: WRITE')
            ->expectsOutputToContain('Regole MioDottore cancellate: 1')
            ->expectsOutputToContain('Eccezioni MioDottore cancellate nel range: 2')
            ->expectsOutputToContain('Regole inserite: 2')
            ->expectsOutputToContain('Eccezioni positive inserite: 1')
            ->expectsOutputToContain('Righe inserite totali: 3')
            ->expectsOutputToContain('Sync completata.')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            ProfessionalAvailabilityException::query()
                ->where('professional_id', $professional->id)
                ->whereNull('source')
                ->whereDate('date', '2026-06-19')
                ->where('type', 'available')
                ->where('start_time', '08:00')
                ->where('end_time', '09:00')
                ->count()
        );

        $this->assertSame(
            1,
            ProfessionalAvailabilityRule::query()
                ->where('professional_id', $professional->id)
                ->whereNull('source')
                ->where('weekday', 1)
                ->where('start_time', '07:00')
                ->where('end_time', '08:00')
                ->count()
        );

        $this->assertDatabaseMissing('professional_availability_exceptions', [
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-29',
            'start_time' => '15:30:00',
            'end_time' => '17:00:00',
            'external_hash' => 'old-split-one',
        ]);

        $this->assertDatabaseMissing('professional_availability_exceptions', [
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-19',
            'type' => 'unavailable',
            'start_time' => '00:00:00',
            'end_time' => '23:59:00',
            'reason' => 'Parigi',
        ]);

        $this->assertSame(
            1,
            ProfessionalAvailabilityException::query()
                ->where('professional_id', $professional->id)
                ->where('source', 'miodottore')
                ->whereDate('date', '2026-06-29')
                ->where('type', 'available')
                ->where('start_time', '15:30')
                ->where('end_time', '20:15')
                ->count()
        );

        $this->assertDatabaseMissing('professional_availability_exceptions', [
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-29',
            'type' => 'available',
            'start_time' => '18:15:00',
            'end_time' => '20:15:00',
        ]);

        $this->assertSame(
            2,
            ProfessionalAvailabilityRule::query()
                ->where('professional_id', $professional->id)
                ->where('source', 'miodottore')
                ->count()
        );

        $this->assertSame(
            1,
            ProfessionalAvailabilityRule::query()
                ->where('professional_id', $professional->id)
                ->where('source', 'miodottore')
                ->where('weekday', 3)
                ->where('start_time', '09:15')
                ->where('end_time', '12:50')
                ->count()
        );

        $this->assertSame(
            1,
            ProfessionalAvailabilityRule::query()
                ->where('professional_id', $professional->id)
                ->where('source', 'miodottore')
                ->where('weekday', 4)
                ->where('start_time', '09:15')
                ->where('end_time', '12:50')
                ->count()
        );

        $this->assertSame(
            1,
            ProfessionalAvailabilityException::query()
                ->where('professional_id', $professional->id)
                ->where('source', 'miodottore')
                ->whereDate('date', '2026-08-01')
                ->where('type', 'available')
                ->where('start_time', '09:00')
                ->where('end_time', '10:00')
                ->where('external_hash', 'outside-range')
                ->count()
        );

        $mapping->refresh();
        $this->assertSame('synced', $mapping->sync_status);
        $this->assertNull($mapping->last_sync_error);
        $this->assertNotNull($mapping->last_synced_at);

        $account = ExternalProviderAccount::query()->where('provider', 'miodottore')->first();
        $this->assertNotNull($account);
        $this->assertTrue((bool) $account->enabled);
        $this->assertNotNull($account->last_availability_sync_at);
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeNormalizedSource(bool $includeUnmapped): array
    {
        $professionals = [
            [
                'provider_schedule_id' => 293871,
                'provider_doctor_id' => 76548,
                'provider_name' => 'Arena, Sebastiano',
                'display_name' => 'Arena, Sebastiano',
                'specialty_id' => 2803,
                'facility_id' => 116391,
                'weekly_hours' => [
                    ['weekday' => 'wednesday', 'start' => '09:15', 'end' => '12:50'],
                    ['weekday' => 'thursday', 'start' => '09:15', 'end' => '12:50'],
                ],
                'daily_available_exceptions' => [
                    ['date' => '2026-06-29', 'weekday' => 'monday', 'start' => '15:30', 'end' => '20:15'],
                ],
                'appointments' => [
                    [
                        'date' => '2026-06-29',
                        'start' => '17:00',
                        'end' => '18:15',
                        'service_name' => 'Prima visita',
                        'status' => 'confirmed',
                    ],
                ],
                'ignored_unavailable_blocks' => [
                    ['date' => '2026-06-19', 'start' => '00:00', 'end' => '23:59', 'label' => 'Parigi'],
                ],
            ],
        ];

        if ($includeUnmapped) {
            $professionals[] = [
                'provider_schedule_id' => 999999,
                'provider_doctor_id' => 11111,
                'provider_name' => 'Medico non mappato',
                'display_name' => 'Medico non mappato',
                'weekly_hours' => [
                    ['weekday' => 'monday', 'start' => '11:00', 'end' => '12:00'],
                ],
                'daily_available_exceptions' => [],
                'appointments' => [],
                'ignored_unavailable_blocks' => [],
            ];
        }

        return [
            'success' => true,
            'message' => 'Lettura disponibilita dichiarate MioDottore completata da api/calendarevents.',
            'output_dir' => 'miodottore-access/availabilities/test-artifacts',
            'state_path' => 'miodottore/storage-state.json',
            'access_check' => [
                'success' => true,
                'result' => [
                    'success' => true,
                    'final_url' => 'https://docplanner.miodottore.it/#/calendar-clinic/day',
                ],
            ],
            'result' => [
                'success' => true,
                'warnings' => [],
            ],
            'normalized' => [
                'provider' => 'miodottore',
                'from' => '2026-06-16',
                'to' => '2026-07-16',
                'source' => 'api/calendarevents',
                'summary' => [
                    'schedules_count' => $includeUnmapped ? 2 : 1,
                    'workperiods_count' => 3,
                    'appointments_count' => 1,
                    'blocks_count' => 1,
                    'normalized_days_count' => 3,
                    'weekly_hours_count' => $includeUnmapped ? 3 : 2,
                    'daily_available_exceptions_count' => 1,
                    'ignored_unavailable_blocks_count' => 1,
                ],
                'professionals' => $professionals,
                'warnings' => [],
            ],
            'summary' => [
                'professionals_count' => $includeUnmapped ? 2 : 1,
                'schedules_count' => $includeUnmapped ? 2 : 1,
                'workperiods_count' => 3,
                'appointments_count' => 1,
                'blocks_count' => 1,
                'normalized_days_count' => 3,
                'weekly_hours_count' => $includeUnmapped ? 3 : 2,
                'daily_available_exceptions_count' => 1,
                'ignored_unavailable_blocks_count' => 1,
                'warnings_count' => 0,
            ],
        ];
    }
}
