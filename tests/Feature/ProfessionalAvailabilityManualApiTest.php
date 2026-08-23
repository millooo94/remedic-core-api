<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfessionalAvailabilityManualApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_rejects_overlapping_manual_recurring_rules(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $professional = Professional::factory()->create();

        $this->postJson('/api/v1/professional-availabilities', [
            'professional_id' => $professional->id,
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'is_active' => true,
            'notes' => null,
        ])->assertCreated();

        $this->postJson('/api/v1/professional-availabilities', [
            'professional_id' => $professional->id,
            'weekday' => 1,
            'start_time' => '12:00',
            'end_time' => '15:00',
            'is_active' => true,
            'notes' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }
}
