<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketingCampaign;
use App\Models\MarketingSegment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketingCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_manual_segments_with_unique_normalized_numbers(): void
    {
        $this->actingAsAdmin();

        $patient = Patient::factory()->create([
            'phone' => '+393331234567',
        ]);

        $response = $this->postJson('/api/v1/marketing-segments', [
            'name' => 'Numeri selezionati manualmente',
            'description' => 'Contatti scelti a mano.',
            'segment_type' => 'manual',
            'manual_numbers' => [
                '3331234567, +39 333 1234567; testo_non_valido',
                '095123456',
            ],
            'is_active' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('segment_type', 'manual')
            ->assertJsonPath('manual_recipients_count', 2)
            ->assertJsonCount(2, 'manual_recipients')
            ->assertJsonPath('manual_recipients.0.patient_id', $patient->id);

        $this->assertDatabaseCount('marketing_segment_manual_recipients', 2);
    }

    #[Test]
    public function it_previews_manual_segments_and_uses_them_for_campaign_counts(): void
    {
        $this->actingAsAdmin();

        $patient = Patient::factory()->create([
            'phone' => '+393331234567',
            'contactable_sms' => true,
            'contactable_whatsapp' => true,
            'contactable_email' => true,
            'email' => 'patient@example.test',
        ]);

        $previewResponse = $this->postJson('/api/v1/marketing-segments/preview', [
            'segment_type' => 'manual',
            'manual_numbers' => [
                '3331234567; non_valido; +393387654321',
            ],
        ]);

        $previewResponse
            ->assertOk()
            ->assertJsonPath('patients_count', 2)
            ->assertJsonCount(1, 'invalid_numbers');

        $segment = MarketingSegment::query()->create([
            'name' => 'Segmento manuale',
            'description' => null,
            'segment_type' => 'manual',
            'filters' => [],
            'last_preview_count' => 2,
            'is_active' => true,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $segment->manualRecipients()->createMany([
            [
                'patient_id' => $patient->id,
                'original_value' => '+393331234567',
                'normalized_phone' => '+393331234567',
                'sort_order' => 0,
            ],
            [
                'patient_id' => null,
                'original_value' => '+393387654321',
                'normalized_phone' => '+393387654321',
                'sort_order' => 1,
            ],
        ]);

        $this->getJson("/api/v1/marketing-segments/{$segment->id}/campaign-preview?channel=sms")
            ->assertOk()
            ->assertJsonPath('segment_patients', 2)
            ->assertJsonPath('eligible_recipients', 2)
            ->assertJsonPath('excluded', 0);

        $this->getJson("/api/v1/marketing-segments/{$segment->id}/campaign-preview?channel=email")
            ->assertOk()
            ->assertJsonPath('segment_patients', 2)
            ->assertJsonPath('eligible_recipients', 1)
            ->assertJsonPath('excluded', 1);
    }

    #[Test]
    public function it_lists_saved_patients_for_manual_segments_and_supports_sex_filters(): void
    {
        $this->actingAsAdmin();

        Patient::factory()->create([
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Rossi Mario',
            'phone' => '+393331111111',
            'sex' => 'male',
        ]);
        Patient::factory()->create([
            'first_name' => 'Anna',
            'last_name' => 'Verdi',
            'full_name' => 'Verdi Anna',
            'phone' => '+393332222222',
            'sex' => 'female',
        ]);
        Patient::factory()->create([
            'first_name' => 'Luca',
            'last_name' => 'Bianchi',
            'full_name' => 'Bianchi Luca',
            'phone' => '+393333333333',
            'sex' => null,
        ]);

        $this->getJson('/api/v1/patients/options')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['full_name' => 'Bianchi Luca'])
            ->assertJsonFragment(['full_name' => 'Rossi Mario'])
            ->assertJsonFragment(['full_name' => 'Verdi Anna']);

        $this->postJson('/api/v1/marketing-segments/preview', [
            'segment_type' => 'filter_based',
            'filters' => [
                ['field' => 'sex', 'operator' => 'eq', 'value' => 'female'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('patients_count', 1);

        $this->postJson('/api/v1/marketing-segments/preview', [
            'segment_type' => 'filter_based',
            'filters' => [
                ['field' => 'sex', 'operator' => 'eq', 'value' => '__null__'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('patients_count', 1);

        $this->postJson('/api/v1/marketing-segments/preview', [
            'segment_type' => 'filter_based',
            'filters' => [
                ['field' => 'sex', 'operator' => 'neq', 'value' => 'male'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('patients_count', 2);
    }

    #[Test]
    public function it_exposes_the_whatsapp_connector_status(): void
    {
        $this->actingAsAdmin();
        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');

        Http::fake([
            'http://whatsapp-connector.test/status' => Http::response([
                'state' => 'connected',
                'ready' => true,
                'message' => 'WhatsApp Web collegato e pronto all\'invio.',
                'qr_required' => false,
                'qr_code_data_url' => null,
                'web_state' => 'CONNECTED',
                'queue_depth' => 0,
                'phone_number' => '393331234567',
                'push_name' => 'Remedic',
                'last_error_code' => null,
                'last_error_message' => null,
                'last_event_at' => now()->toIso8601String(),
                'last_connected_at' => now()->toIso8601String(),
            ]),
        ]);

        $this->getJson('/api/v1/marketing-whatsapp/status')
            ->assertOk()
            ->assertJsonPath('state', 'connected')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('phone_number', '393331234567');
    }

    #[Test]
    public function it_blocks_whatsapp_campaign_launch_when_the_connector_is_not_ready(): void
    {
        $this->actingAsAdmin();
        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');

        Http::fake([
            'http://whatsapp-connector.test/status' => Http::response([
                'state' => 'qr_required',
                'ready' => false,
                'message' => 'WhatsApp non collegato: apri il QR code e completa l\'accesso prima di inviare.',
                'qr_required' => true,
                'qr_code_data_url' => 'data:image/png;base64,test',
                'queue_depth' => 0,
            ]),
        ]);

        $campaign = $this->createCampaign('whatsapp');

        $this->postJson("/api/v1/marketing-campaigns/{$campaign->id}/launch", [
            'scheduled_at' => null,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
    }

    #[Test]
    public function it_records_structured_whatsapp_test_failures_without_throwing_raw_errors(): void
    {
        $this->actingAsAdmin();
        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');

        Http::fake([
            'http://whatsapp-connector.test/status' => Http::response([
                'state' => 'connected',
                'ready' => true,
                'message' => 'WhatsApp Web collegato e pronto all\'invio.',
                'qr_required' => false,
                'qr_code_data_url' => null,
                'queue_depth' => 0,
            ]),
            'http://whatsapp-connector.test/send' => Http::response([
                'delivery_status' => 'excluded',
                'provider_status' => 'no_whatsapp',
                'message_id' => null,
                'error_message' => 'Numero non disponibile su WhatsApp.',
                'response' => [
                    'normalized_target' => '393331234567',
                ],
            ]),
        ]);

        $campaign = $this->createCampaign('whatsapp');

        $this->postJson("/api/v1/marketing-campaigns/{$campaign->id}/send-test", [
            'target' => '+393331234567',
        ])
            ->assertCreated()
            ->assertJsonPath('delivery_status', 'excluded')
            ->assertJsonPath('provider_status', 'no_whatsapp')
            ->assertJsonPath('error_message', 'Numero non disponibile su WhatsApp.');
    }

    #[Test]
    public function it_stores_a_whatsapp_image_only_for_channels_that_include_whatsapp(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $segment = MarketingSegment::query()->create([
            'name' => 'Segmento test',
            'description' => 'Segmento per test marketing.',
            'filters' => [],
            'last_preview_count' => 0,
            'is_active' => true,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $response = $this->post('/api/v1/marketing-campaigns', [
            'name' => 'Campagna con immagine',
            'marketing_segment_id' => $segment->id,
            'channel' => 'whatsapp',
            'template_key' => null,
            'subject' => null,
            'message' => 'Messaggio con immagine',
            'scheduled_at' => null,
            'whatsapp_image' => UploadedFile::fake()->image('promo-whatsapp.png', 1200, 1200)->size(256),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertSuccessful()
            ->assertJsonPath('channel', 'whatsapp')
            ->assertJsonPath('whatsapp_image_name', 'promo-whatsapp.png');

        /** @var MarketingCampaign $campaign */
        $campaign = MarketingCampaign::query()->latest('id')->firstOrFail();

        $this->assertNotNull($campaign->whatsapp_image_path);
        $this->assertSame('promo-whatsapp.png', $campaign->whatsapp_image_original_name);
        Storage::disk('public')->assertExists($campaign->whatsapp_image_path);

        $this->post('/api/v1/marketing-campaigns', [
            'name' => 'Campagna SMS con immagine',
            'marketing_segment_id' => $segment->id,
            'channel' => 'sms',
            'template_key' => null,
            'subject' => null,
            'message' => 'Messaggio SMS',
            'scheduled_at' => null,
            'whatsapp_image' => UploadedFile::fake()->image('non-consentita.png'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['whatsapp_image']);
    }

    #[Test]
    public function it_forwards_whatsapp_image_metadata_to_the_connector_during_test_send(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();
        config()->set('services.whatsapp_puppeteer.base_url', 'http://whatsapp-connector.test');

        $storedPath = UploadedFile::fake()
            ->image('promo-test.png', 600, 600)
            ->store('marketing-campaigns', 'public');

        Http::fake([
            'http://whatsapp-connector.test/status' => Http::response([
                'state' => 'connected',
                'ready' => true,
                'message' => 'WhatsApp Web collegato e pronto all\'invio.',
                'qr_required' => false,
                'qr_code_data_url' => null,
                'queue_depth' => 0,
            ]),
            'http://whatsapp-connector.test/send' => Http::response([
                'delivery_status' => 'sent',
                'provider_status' => 'sent',
                'message_id' => 'wa-test-001',
                'response' => [
                    'media_sent' => true,
                ],
            ]),
        ]);

        $campaign = $this->createCampaign('whatsapp');
        $campaign->forceFill([
            'whatsapp_image_path' => $storedPath,
            'whatsapp_image_original_name' => 'promo-test.png',
            'whatsapp_image_mime_type' => 'image/png',
            'whatsapp_image_size' => 12345,
        ])->save();

        $this->postJson("/api/v1/marketing-campaigns/{$campaign->id}/send-test", [
            'target' => '+393331234567',
        ])
            ->assertCreated()
            ->assertJsonPath('delivery_status', 'sent');

        Http::assertSent(function ($request) use ($campaign): bool {
            if ($request->url() !== 'http://whatsapp-connector.test/send') {
                return false;
            }

            $data = $request->data();

            return ($data['target'] ?? null) === '+393331234567'
                && ($data['media_name'] ?? null) === 'promo-test.png'
                && ($data['media_mime_type'] ?? null) === 'image/png'
                && filled($data['media_base64'] ?? null)
                && ($data['media_path'] ?? null) === Storage::disk('public')->path($campaign->whatsapp_image_path);
        });
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function createCampaign(string $channel): MarketingCampaign
    {
        $segment = MarketingSegment::query()->create([
            'name' => 'Segmento test',
            'description' => 'Segmento per test marketing.',
            'filters' => [],
            'last_preview_count' => 0,
            'is_active' => true,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        return MarketingCampaign::query()->create([
            'name' => 'Campagna test',
            'marketing_segment_id' => $segment->id,
            'channel' => $channel,
            'template_key' => null,
            'subject' => 'Oggetto test',
            'message' => 'Messaggio test',
            'status' => 'draft',
            'recipients_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'excluded_count' => 0,
            'created_by' => 1,
            'updated_by' => 1,
        ]);
    }
}
