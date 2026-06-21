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
            'google_review_delay_days' => 3,
            'google_review_delay_hours' => 0,
            'google_review_delay_minutes' => 0,
            'google_review_delay_seconds' => 0,
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
        $expectedSchedule = $performanceRecord->created_at?->copy()->addDays(3)->setTimezone(config('app.timezone', 'UTC'));

        $this->assertNotNull($expectedSchedule);
        $this->assertSame($expectedSchedule?->format('Y-m-d H:i:s'), $request->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('grazie per aver scelto remedic', mb_strtolower((string) $request->message_body));
        $this->assertStringContainsString('Gentile sig.ra Verdi,', (string) $request->message_body);
        $this->assertStringContainsString('il nostro cardiologo', mb_strtolower((string) $request->message_body));
        $this->assertStringContainsString('il dott. Giuseppe Bottaro', (string) $request->message_body);
        $this->assertStringNotContainsString('specialista in', mb_strtolower((string) $request->message_body));
        Carbon::setTestNow();
    }

    #[Test]
    public function it_lists_and_updates_google_review_requests_from_the_api(): void
    {
        $this->actingAsAdmin();
        Carbon::setTestNow(Carbon::parse('2026-04-08 10:30:00', 'Europe/Rome'));
        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
            'google_review_delay_days' => 3,
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

        $this->postJson("/api/v1/google-review-requests/{$request->id}/exclude")
            ->assertOk()
            ->assertJsonPath('data.status', 'excluded');

        $this->postJson("/api/v1/google-review-requests/{$request->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
        Carbon::setTestNow();
    }

    #[Test]
    public function it_exposes_and_updates_google_review_delay_settings(): void
    {
        $this->actingAsAdmin();

        SiteSetting::singleton()->forceFill([
            'google_review_url' => 'https://g.page/r/remedic/review',
            'google_review_delay_days' => 3,
            'google_review_delay_hours' => 0,
            'google_review_delay_minutes' => 0,
            'google_review_delay_seconds' => 0,
        ])->save();

        $this->getJson('/api/v1/google-review-requests/settings')
            ->assertOk()
            ->assertJsonPath('google_review_delay_days', 3)
            ->assertJsonPath('google_review_delay_hours', 0)
            ->assertJsonPath('google_review_delay_minutes', 0)
            ->assertJsonPath('google_review_delay_seconds', 0);

        $this->putJson('/api/v1/google-review-requests/settings', [
            'google_review_url' => 'https://g.page/r/remedic/review',
            'google_review_delay_days' => 1,
            'google_review_delay_hours' => 6,
            'google_review_delay_minutes' => 15,
            'google_review_delay_seconds' => 30,
        ])->assertOk()
            ->assertJsonPath('settings.google_review_delay_days', 1)
            ->assertJsonPath('settings.google_review_delay_hours', 6)
            ->assertJsonPath('settings.google_review_delay_minutes', 15)
            ->assertJsonPath('settings.google_review_delay_seconds', 30);
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

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function createProfessionalServiceContext(): array
    {
        $slugSuffix = strtolower((string) str()->random(8));
        $professional = Professional::factory()->create([
            'first_name' => 'Giuseppe',
            'last_name' => 'Bottaro',
            'full_name' => 'Bottaro Giuseppe',
            'area_name' => 'Cardiologia',
        ]);
        $category = ServiceCategory::factory()->create([
            'name' => 'Cardiologia '.$slugSuffix,
            'slug' => 'cardiologia-'.$slugSuffix,
        ]);
        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica',
            'canonical_name' => 'Visita cardiologica',
            'slug' => 'cardiologia-visita-cardiologica-'.$slugSuffix,
        ]);

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
