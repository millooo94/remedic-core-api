<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ExternalProviderProfessional;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityException;
use App\Models\ProfessionalAvailabilityRule;
use App\Models\User;
use App\Services\MiodottoreAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfessionalImportedAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_imported_miodottore_availabilities_for_a_professional(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create([
            'full_name' => 'Rossi Mario',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
        ]);

        ExternalProviderProfessional::query()->create([
            'professional_id' => $professional->id,
            'provider' => 'miodottore',
            'external_name' => 'Dr. Mario Rossi',
            'external_url' => 'https://www.miodottore.it/professionisti/mario-rossi/orari',
            'enabled' => true,
            'sync_status' => 'synced',
            'last_synced_at' => '2026-06-15 03:20:00',
        ]);

        ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'weekday' => 3,
            'start_time' => '09:15',
            'end_time' => '12:50',
            'is_active' => true,
            'external_hash' => 'rule-a',
            'last_synced_at' => '2026-06-15 03:20:00',
        ]);

        ProfessionalAvailabilityRule::query()->create([
            'professional_id' => $professional->id,
            'source' => null,
            'weekday' => 4,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'is_active' => true,
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-19',
            'type' => 'available',
            'start_time' => '10:00',
            'end_time' => '13:00',
            'reason' => 'Prenotabile online',
            'external_hash' => 'exc-a',
            'last_synced_at' => '2026-06-15 03:20:00',
        ]);

        $this->getJson("/api/v1/professionals/{$professional->id}/availabilities")
            ->assertOk()
            ->assertJsonPath('source', 'miodottore')
            ->assertJsonPath('source_label', 'MioDottore')
            ->assertJsonPath('sync_status', 'synced')
            ->assertJsonPath('sync_status_label', 'Sincronizzato')
            ->assertJsonPath('provider_profile.external_name', 'Dr. Mario Rossi')
            ->assertJsonPath('provider_profile.external_url', 'https://www.miodottore.it/professionisti/mario-rossi/orari')
            ->assertJsonPath('provider_profile.is_configured', true)
            ->assertJsonCount(1, 'recurring_rules')
            ->assertJsonCount(1, 'daily_exceptions')
            ->assertJsonPath('recurring_rules.0.source', 'miodottore')
            ->assertJsonPath('daily_exceptions.0.source', 'miodottore');
    }

    #[Test]
    public function it_returns_not_configured_when_the_professional_has_no_miodottore_url(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create([
            'full_name' => 'Bianchi Laura',
            'first_name' => 'Laura',
            'last_name' => 'Bianchi',
        ]);

        $this->postJson("/api/v1/professionals/{$professional->id}/availabilities/sync")
            ->assertAccepted()
            ->assertJsonPath('status', 'not_configured')
            ->assertJsonPath('message', 'URL MioDottore del professionista non configurato.')
            ->assertJsonPath('sync_status', 'not_configured')
            ->assertJsonPath('sync_status_label', 'Non configurata');

        $providerProfile = ExternalProviderProfessional::query()
            ->where('professional_id', $professional->id)
            ->where('provider', 'miodottore')
            ->first();

        $this->assertNotNull($providerProfile);
        $this->assertSame('not_configured', $providerProfile->sync_status);
        $this->assertSame('URL MioDottore del professionista non configurato.', $providerProfile->last_sync_error);
    }

    #[Test]
    public function it_runs_the_real_sync_for_the_selected_professional_and_returns_the_refreshed_snapshot(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create([
            'full_name' => 'Laura Bianchi',
            'first_name' => 'Laura',
            'last_name' => 'Bianchi',
        ]);

        ExternalProviderProfessional::query()->create([
            'professional_id' => $professional->id,
            'provider' => 'miodottore',
            'external_name' => 'Dr. Laura Bianchi',
            'external_id' => '293871',
            'external_url' => 'https://www.miodottore.it/professionisti/laura-bianchi/orari',
            'enabled' => true,
            'sync_status' => 'never_synced',
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-19',
            'type' => 'unavailable',
            'start_time' => '00:00',
            'end_time' => '23:59',
            'reason' => 'Parigi',
            'external_hash' => 'old-parigi',
            'last_synced_at' => now()->subDay(),
        ]);

        ProfessionalAvailabilityException::query()->create([
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'date' => '2026-06-29',
            'type' => 'available',
            'start_time' => '15:30',
            'end_time' => '17:00',
            'reason' => null,
            'external_hash' => 'old-split-a',
            'last_synced_at' => now()->subDay(),
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifySavedAccess')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'Accesso MioDottore verificato correttamente.',
                    'result' => ['status' => 'session_valid'],
                ]);

            $mock->shouldReceive('debugMiodottoreAvailabilities')
                ->once()
                ->andReturn([
                    'success' => true,
                    'message' => 'Lettura disponibilita dichiarate MioDottore completata da api/calendarevents.',
                    'access_check' => ['success' => true],
                    'normalized' => [
                        'provider' => 'miodottore',
                        'from' => '2026-06-16',
                        'to' => '2026-07-16',
                        'summary' => [
                            'appointments_count' => 1,
                        ],
                        'professionals' => [
                            [
                                'provider_schedule_id' => 293871,
                                'provider_name' => 'Dr. Laura Bianchi',
                                'display_name' => 'Dr. Laura Bianchi',
                                'weekly_hours' => [
                                    ['weekday' => 'wednesday', 'start' => '09:15', 'end' => '12:50'],
                                    ['weekday' => 'thursday', 'start' => '09:15', 'end' => '12:50'],
                                ],
                                'daily_available_exceptions' => [
                                    ['date' => '2026-06-29', 'start' => '15:30', 'end' => '20:15'],
                                ],
                                'appointments' => [
                                    ['date' => '2026-06-29', 'start' => '17:00', 'end' => '18:15'],
                                ],
                                'ignored_unavailable_blocks' => [
                                    ['date' => '2026-06-19', 'start' => '00:00', 'end' => '23:59', 'label' => 'Parigi'],
                                ],
                            ],
                        ],
                    ],
                ]);
        });

        $this->postJson("/api/v1/professionals/{$professional->id}/availabilities/sync")
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', 'Disponibilita MioDottore sincronizzate correttamente.')
            ->assertJsonPath('summary.professionals_matched', 1)
            ->assertJsonPath('summary.weekly_hours_written', 2)
            ->assertJsonPath('summary.daily_available_exceptions_written', 1)
            ->assertJsonPath('summary.unavailable_blocks_ignored', 1)
            ->assertJsonPath('summary.appointments_ignored_for_availability_split', 1)
            ->assertJsonPath('sync_status', 'synced')
            ->assertJsonCount(2, 'recurring_rules')
            ->assertJsonCount(1, 'daily_exceptions');

        $this->assertDatabaseMissing('professional_availability_exceptions', [
            'professional_id' => $professional->id,
            'source' => 'miodottore',
            'type' => 'unavailable',
            'reason' => 'Parigi',
        ]);

        $this->assertSame(
            1,
            ProfessionalAvailabilityException::query()
                ->where('professional_id', $professional->id)
                ->where('source', 'miodottore')
                ->whereDate('date', '2026-06-29')
                ->where('start_time', '15:30')
                ->where('end_time', '20:15')
                ->count()
        );
    }

    #[Test]
    public function it_marks_the_global_miodottore_account_as_expired_when_professional_sync_detects_an_invalid_session(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create([
            'full_name' => 'Giuseppe Verdi',
            'first_name' => 'Giuseppe',
            'last_name' => 'Verdi',
        ]);

        ExternalProviderProfessional::query()->create([
            'professional_id' => $professional->id,
            'provider' => 'miodottore',
            'external_name' => 'Dr. Giuseppe Verdi',
            'external_id' => '293871',
            'external_url' => 'https://www.miodottore.it/professionisti/giuseppe-verdi/orari',
            'enabled' => true,
            'sync_status' => 'synced',
        ]);

        $this->mock(MiodottoreAccessService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifySavedAccess')
                ->once()
                ->andReturn([
                    'success' => false,
                    'message' => 'Sessione MioDottore non valida o scaduta.',
                    'result' => ['status' => 'session_expired'],
                ]);
        });

        $this->postJson("/api/v1/professionals/{$professional->id}/availabilities/sync")
            ->assertAccepted()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'session_expired')
            ->assertJsonPath('message', 'Sessione MioDottore non valida. Ricollega MioDottore prima di sincronizzare.')
            ->assertJsonPath('sync_status', 'error');

        $account = \App\Models\ExternalProviderAccount::query()->where('provider', 'miodottore')->first();
        $this->assertNotNull($account);
        $this->assertSame('session_expired', $account->login_status);
        $this->assertSame('Sessione MioDottore non valida. Ricollega MioDottore prima di sincronizzare.', $account->last_error);
    }

    #[Test]
    public function it_updates_the_miodottore_external_url_for_a_professional(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create([
            'full_name' => 'Neri Giulia',
            'first_name' => 'Giulia',
            'last_name' => 'Neri',
        ]);

        $this->putJson("/api/v1/professionals/{$professional->id}/availabilities/provider-profile", [
            'external_url' => 'https://www.miodottore.it/professionisti/giulia-neri/orari',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'URL MioDottore salvato.')
            ->assertJsonPath('provider_profile.external_url', 'https://www.miodottore.it/professionisti/giulia-neri/orari')
            ->assertJsonPath('provider_profile.is_configured', true)
            ->assertJsonPath('sync_status', 'never_synced');

        $providerProfile = ExternalProviderProfessional::query()
            ->where('professional_id', $professional->id)
            ->where('provider', 'miodottore')
            ->first();

        $this->assertNotNull($providerProfile);
        $this->assertSame('https://www.miodottore.it/professionisti/giulia-neri/orari', $providerProfile->external_url);
    }
}
