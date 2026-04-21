<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReminderApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_and_manages_reminders(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $created = $this->postJson('/api/v1/reminders', [
            'title' => 'Promemoria conteggi mensili',
            'recipient_email' => 'humancaretelemedicine@gmail.com',
            'subject' => 'Promemoria prospetti professionisti Remedic',
            'body' => 'Controllare i conteggi e preparare i prospetti.',
            'frequency' => 'monthly',
            'day_of_month' => 20,
            'is_active' => true,
        ])->assertCreated();

        $id = $created->json('id');

        $this->getJson('/api/v1/reminders')
            ->assertOk()
            ->assertJsonFragment(['id' => $id, 'title' => 'Promemoria conteggi mensili']);

        $this->putJson("/api/v1/reminders/{$id}", [
            'title' => 'Promemoria conteggi trimestrali',
            'recipient_email' => 'humancaretelemedicine@gmail.com',
            'subject' => 'Promemoria aggiornato',
            'body' => 'Nuovo testo promemoria.',
            'frequency' => 'monthly',
            'day_of_month' => 20,
            'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->deleteJson("/api/v1/reminders/{$id}")
            ->assertNoContent();
    }
}

