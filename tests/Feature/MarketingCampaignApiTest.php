<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MarketingSegment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonFragment(['full_name' => 'Luca Bianchi'])
            ->assertJsonFragment(['full_name' => 'Mario Rossi'])
            ->assertJsonFragment(['full_name' => 'Anna Verdi']);

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
    public function it_accepts_only_current_campaign_channels_and_requires_an_email_subject(): void
    {
        $this->actingAsAdmin();

        $segment = MarketingSegment::query()->create([
            'name' => 'Segmento campagne',
            'description' => null,
            'segment_type' => 'filter_based',
            'filters' => [],
            'last_preview_count' => 0,
            'is_active' => true,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $basePayload = [
            'name' => 'Campagna test',
            'marketing_segment_id' => $segment->id,
            'message' => 'Messaggio di prova',
        ];

        $this->postJson('/api/v1/marketing-campaigns', [
            ...$basePayload,
            'channel' => 'whatsapp',
        ])->assertUnprocessable()->assertJsonValidationErrors('channel');

        $this->postJson('/api/v1/marketing-campaigns', [
            ...$basePayload,
            'channel' => 'email',
        ])->assertUnprocessable()->assertJsonValidationErrors('subject');

        $this->postJson('/api/v1/marketing-campaigns', [
            ...$basePayload,
            'channel' => 'sms',
        ])->assertCreated()->assertJsonPath('channel', 'sms');
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }
}
