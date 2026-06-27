<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ExternalProviderAccount;
use App\Models\GoogleReviewRequest;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SiteSetting;
use App\Models\Specialization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleReviewRequestApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_a_pending_google_review_request_when_a_performance_record_is_saved(): void
    {
        $this->actingAsAdmin();
        Carbon::setTestNow(Carbon::parse('2026-04-08 10:30:00', 'Europe/Rome'));
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Verdi',
            'full_name' => 'Anna Verdi',
            'sex' => 'female',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $recordId = (int) $response->json('id');

        $this->assertDatabaseHas('google_review_requests', [
            'performance_record_id' => $recordId,
            'patient_id' => $patient->id,
            'status' => 'pending',
            'patient_name' => 'Anna Verdi',
            'patient_phone' => '+393331234567',
            'review_url' => 'https://g.page/r/remedic/review',
        ]);

        $performanceRecord = \App\Models\PerformanceRecord::query()->findOrFail($recordId);
        $request = GoogleReviewRequest::query()->where('performance_record_id', $recordId)->firstOrFail();
        $expectedSchedule = Carbon::parse('2026-04-08 16:30:00', 'Europe/Rome')
            ->setTimezone(config('app.timezone', 'UTC'));

        $this->assertNotNull($expectedSchedule);
        $this->assertSame($expectedSchedule?->format('Y-m-d H:i:s'), $request->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame('morning', $performanceRecord->visit_shift?->value);
        $this->assertStringContainsString('grazie per aver scelto remedic', mb_strtolower((string) $request->message_body));
        $this->assertStringContainsString('Gentile sig.ra Verdi,', (string) $request->message_body);
        $this->assertStringContainsString('il nostro cardiologo', mb_strtolower((string) $request->message_body));
        $this->assertStringContainsString('il Dott. Giuseppe Bottaro', (string) $request->message_body);
        $this->assertStringNotContainsString('specialista in', mb_strtolower((string) $request->message_body));
        Carbon::setTestNow();
    }

    #[Test]
    public function it_schedules_google_review_requests_using_visit_shift_and_registration_time(): void
    {
        $this->actingAsAdmin();
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create([
            'first_name' => 'Luca',
            'last_name' => 'Bianchi',
            'full_name' => 'Luca Bianchi',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-08 19:30:00', 'Europe/Rome'));
        $lateMorningResponse = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $lateMorningRequest = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $lateMorningResponse->json('id'))
            ->firstOrFail();
        $this->assertSame(
            Carbon::parse('2026-04-09 10:00:00', 'Europe/Rome')->utc()->format('Y-m-d H:i:s'),
            $lateMorningRequest->scheduled_at?->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow(Carbon::parse('2026-04-08 16:00:00', 'Europe/Rome'));
        $afternoonResponse = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'visit_shift' => 'afternoon',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $afternoonRequest = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $afternoonResponse->json('id'))
            ->firstOrFail();
        $this->assertSame(
            Carbon::parse('2026-04-09 10:00:00', 'Europe/Rome')->utc()->format('Y-m-d H:i:s'),
            $afternoonRequest->scheduled_at?->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function it_uses_the_service_specialization_title_instead_of_the_professional_primary_specialization(): void
    {
        $this->actingAsAdmin();
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext(
            professionalGender: 'female',
            professionalFirstName: 'Agata',
            professionalLastName: 'Di Dio',
            primaryProfessionalSpecialization: [
                'name' => 'Dietologia',
                'male' => 'dietologo',
                'female' => 'dietologa',
            ],
            serviceSpecialization: [
                'name' => 'Medicina estetica',
                'male' => 'medico estetico',
                'female' => 'medica estetica',
            ],
        );

        $patient = Patient::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Verdi',
            'full_name' => 'Anna Verdi',
            'sex' => 'female',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $request = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $response->json('id'))
            ->firstOrFail();

        $this->assertSame('Medicina estetica', $request->specialization_name);
        $this->assertStringContainsString('la nostra medica estetica', mb_strtolower((string) $request->message_body));
        $this->assertStringContainsString('la Dott.ssa Agata Di Dio', (string) $request->message_body);
        $this->assertStringNotContainsString('dietolog', mb_strtolower((string) $request->message_body));
    }

    #[Test]
    public function it_falls_back_to_a_neutral_message_when_gender_or_service_specialization_are_not_reliable(): void
    {
        $this->actingAsAdmin();
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext(
            professionalGender: 'unspecified',
            attachServiceSpecialization: false,
        );

        $patient = Patient::factory()->create([
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Mario Rossi',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $request = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $response->json('id'))
            ->firstOrFail();

        $this->assertNull($request->specialization_id);
        $this->assertStringContainsString('Speriamo che la sua esperienza presso Remedic sia stata positiva.', (string) $request->message_body);
        $this->assertStringNotContainsString('Dott.', (string) $request->message_body);
    }

    #[Test]
    public function it_lists_and_updates_google_review_requests_from_the_api(): void
    {
        $this->actingAsAdmin();
        Carbon::setTestNow(Carbon::parse('2026-04-08 10:30:00', 'Europe/Rome'));
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create([
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Mario Rossi',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $request = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $response->json('id'))
            ->firstOrFail();

        $this->getJson('/api/v1/google-review-requests?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonPath('data.0.status', 'pending');

        $this->putJson("/api/v1/google-review-requests/{$request->id}/schedule", [
            'scheduled_at' => '2026-04-09T11:15',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.manual_override', true);

        $this->postJson("/api/v1/google-review-requests/{$request->id}/cancel", [
            'reason' => 'Annullato dal centro.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson("/api/v1/google-review-requests/{$request->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('google_review_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
        Carbon::setTestNow();
    }

    #[Test]
    public function it_does_not_create_or_duplicate_google_review_requests_when_a_performance_record_is_updated(): void
    {
        $this->actingAsAdmin();
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Verdi',
            'full_name' => 'Anna Verdi',
            'sex' => 'female',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $created = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
            'notes' => 'Prima versione',
        ])->assertCreated();

        $recordId = (int) $created->json('id');
        $reviewRequestId = GoogleReviewRequest::query()
            ->where('performance_record_id', $recordId)
            ->value('id');

        $this->assertNotNull($reviewRequestId);
        $this->assertSame(1, GoogleReviewRequest::query()->where('performance_record_id', $recordId)->count());

        $this->putJson("/api/v1/performance-records/{$recordId}", [
            'performed_at' => '2026-04-08',
            'visit_shift' => 'afternoon',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 125,
            'payment_method' => 'card',
            'payment_status' => 'pagata',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
            'notes' => 'Correzione amministrativa',
        ])->assertOk();

        $this->assertSame(1, GoogleReviewRequest::query()->where('performance_record_id', $recordId)->count());
        $this->assertDatabaseHas('google_review_requests', [
            'id' => $reviewRequestId,
            'performance_record_id' => $recordId,
        ]);
    }

    #[Test]
    public function it_allows_deleting_only_cancelled_google_review_requests(): void
    {
        $this->actingAsAdmin();
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create([
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Mario Rossi',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-04-08',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $request = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $response->json('id'))
            ->firstOrFail();

        $this->deleteJson("/api/v1/google-review-requests/{$request->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['request']);

        $this->postJson("/api/v1/google-review-requests/{$request->id}/cancel", [
            'reason' => 'Annullato dal centro.',
        ])->assertOk();

        $this->deleteJson("/api/v1/google-review-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Richiesta recensione annullata eliminata.');

        $this->assertDatabaseMissing('google_review_requests', [
            'id' => $request->id,
        ]);
    }

    #[Test]
    public function it_exposes_and_updates_google_review_link_settings(): void
    {
        $this->actingAsAdmin();

        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        $this->getJson('/api/v1/google-review-requests/settings')
            ->assertOk()
            ->assertJsonPath('google_review_url', 'https://g.page/r/remedic/review');

        $this->putJson('/api/v1/google-review-requests/settings', [
            'google_review_url' => 'https://g.page/r/remedic/reviews-updated',
        ])->assertOk()
            ->assertJsonPath('settings.google_review_url', 'https://g.page/r/remedic/reviews-updated');
    }

    #[Test]
    public function the_scheduled_command_sends_pending_google_reviews_through_the_whatsapp_integration(): void
    {
        $this->actingAsAdmin();
        Carbon::setTestNow(Carbon::parse('2026-06-18 10:45:00', 'Europe/Rome'));
        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ExternalProviderAccount::query()->create([
            'provider' => 'whatsapp',
            'label' => 'WhatsApp Business',
            'enabled' => true,
            'login_status' => 'session_valid',
            'config_json' => [
                'review_template_name' => 'google_review_request',
                'review_template_language' => 'it',
            ],
        ]);

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create([
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Mario Rossi',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-06-15',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $request = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $response->json('id'))
            ->firstOrFail();

        $request->forceFill([
            'scheduled_at' => now()->subMinutes(10),
            'status' => 'pending',
        ])->save();

        Http::fake([
            'http://whatsapp-connector.test/status' => Http::response([
                'state' => 'connected',
                'ready' => true,
                'message' => 'WhatsApp Web collegato e pronto all invio.',
                'qr_required' => false,
                'qr_code_data_url' => null,
                'queue_depth' => 0,
                'phone_number' => '393331234567',
                'push_name' => 'Remedic',
                'last_connected_at' => now()->toIso8601String(),
            ]),
            'http://whatsapp-connector.test/send' => Http::response([
                'delivery_status' => 'sent',
                'provider_status' => 'sent',
                'message_id' => 'wa-review-001',
                'response' => [
                    'queued' => true,
                ],
            ]),
        ]);

        $this->artisan('google-reviews:send-pending')
            ->expectsOutputToContain('Richieste recensione inviate: 1')
            ->assertExitCode(0);

        $request->refresh();

        $this->assertSame('sent', $request->status);
        $this->assertNotNull($request->sent_at);
        $this->assertSame('wa-review-001', $request->provider_message_id);

        Carbon::setTestNow();
    }

    #[Test]
    public function the_scheduled_command_does_not_mark_a_google_review_as_sent_when_whatsapp_ack_is_not_confirmed(): void
    {
        $this->actingAsAdmin();
        Carbon::setTestNow(Carbon::parse('2026-06-18 10:45:00', 'Europe/Rome'));
        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
        ])->save();

        ExternalProviderAccount::query()->create([
            'provider' => 'whatsapp',
            'label' => 'WhatsApp Business',
            'enabled' => true,
            'login_status' => 'session_valid',
            'config_json' => [
                'review_template_name' => 'google_review_request',
                'review_template_language' => 'it',
            ],
        ]);

        ['professional' => $professional, 'service' => $service] = $this->createProfessionalServiceContext();
        $patient = Patient::factory()->create([
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Mario Rossi',
            'phone' => '+393331234567',
            'contactable_whatsapp' => true,
            'excluded_from_campaigns' => false,
        ]);

        $response = $this->postJson('/api/v1/performance-records', [
            'performed_at' => '2026-06-15',
            'visit_shift' => 'morning',
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'patient_ids' => [$patient->id],
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_black' => false,
            'is_promo' => false,
        ])->assertCreated();

        $request = GoogleReviewRequest::query()
            ->where('performance_record_id', (int) $response->json('id'))
            ->firstOrFail();

        $request->forceFill([
            'scheduled_at' => now()->subMinutes(10),
            'status' => 'pending',
        ])->save();

        Http::fake([
            'http://whatsapp-connector.test/status' => Http::response([
                'state' => 'connected',
                'ready' => true,
                'message' => 'WhatsApp Web collegato e pronto all invio.',
                'qr_required' => false,
                'qr_code_data_url' => null,
                'queue_depth' => 0,
                'phone_number' => '393331234567',
                'push_name' => 'Remedic',
                'last_connected_at' => now()->toIso8601String(),
            ]),
            'http://whatsapp-connector.test/send' => Http::response([
                'delivery_status' => 'failed',
                'provider_status' => 'send_not_confirmed',
                'message_id' => 'wa-review-pending-ack',
                'error_message' => 'WhatsApp non ha confermato l invio del messaggio.',
                'response' => [
                    'chat_id' => '198934312050832@lid',
                    'ack' => 0,
                    'initial_ack' => 0,
                    'final_ack' => 0,
                    'from_me' => true,
                    'media_sent' => false,
                ],
            ]),
        ]);

        $this->artisan('google-reviews:send-pending')
            ->expectsOutputToContain('Richieste recensione inviate: 0')
            ->assertExitCode(0);

        $request->refresh();

        $this->assertSame('error', $request->status);
        $this->assertNull($request->sent_at);
        $this->assertSame('send_not_confirmed', $request->provider_status);
        $this->assertSame('wa-review-pending-ack', $request->provider_message_id);
        $this->assertSame('WhatsApp non ha confermato l\'invio del messaggio.', $request->error_message);
        $this->assertSame(0, data_get($request->provider_response, 'ack'));

        Carbon::setTestNow();
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function createProfessionalServiceContext(
        string $professionalGender = 'male',
        string $professionalFirstName = 'Giuseppe',
        string $professionalLastName = 'Bottaro',
        ?array $primaryProfessionalSpecialization = null,
        ?array $serviceSpecialization = null,
        bool $attachServiceSpecialization = true,
    ): array
    {
        $primaryProfessionalSpecialization ??= [
            'name' => 'Cardiologia',
            'male' => 'cardiologo',
            'female' => 'cardiologa',
        ];
        $serviceSpecialization ??= $primaryProfessionalSpecialization;

        $slugSuffix = strtolower((string) str()->random(8));
        $professional = Professional::factory()->create([
            'first_name' => $professionalFirstName,
            'last_name' => $professionalLastName,
            'full_name' => trim($professionalLastName.' '.$professionalFirstName),
            'gender' => $professionalGender,
            'area_name' => $primaryProfessionalSpecialization['name'],
        ]);

        $professionalSpecialization = Specialization::query()->create([
            'name' => $primaryProfessionalSpecialization['name'],
            'slug' => 'professional-'.$slugSuffix,
            'professional_title_male' => $primaryProfessionalSpecialization['male'],
            'professional_title_female' => $primaryProfessionalSpecialization['female'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $professional->specializations()->sync([
            $professionalSpecialization->id => [
                'is_primary' => true,
                'sort_order' => 0,
            ],
        ]);

        $category = ServiceCategory::factory()->create([
            'name' => $serviceSpecialization['name'].' '.$slugSuffix,
            'slug' => str($serviceSpecialization['name'])->slug()->append('-'.$slugSuffix)->value(),
        ]);
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Prestazione '.$serviceSpecialization['name'],
            'canonical_name' => 'Prestazione '.$serviceSpecialization['name'],
            'slug' => 'prestazione-'.str($serviceSpecialization['name'])->slug()->append('-'.$slugSuffix)->value(),
        ]);

        if ($attachServiceSpecialization) {
            $serviceModelSpecialization = Specialization::query()->create([
                'name' => $serviceSpecialization['name'],
                'slug' => 'service-'.$slugSuffix,
                'professional_title_male' => $serviceSpecialization['male'],
                'professional_title_female' => $serviceSpecialization['female'],
                'is_active' => true,
                'sort_order' => 0,
            ]);

            $service->specializations()->sync([
                $serviceModelSpecialization->id => [
                    'is_primary' => true,
                    'sort_order' => 0,
                ],
            ]);
        }

        ProfessionalService::query()->create([
            'professional_id' => $professional->id,
            'service_id' => $service->id,
            'price_amount' => 100,
            'is_active' => true,
        ]);

        return [
            'professional' => $professional,
            'service' => $service,
        ];
    }
}
