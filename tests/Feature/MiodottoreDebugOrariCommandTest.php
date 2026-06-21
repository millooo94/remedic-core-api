<?php

namespace Tests\Feature;

use App\Models\ExternalProviderProfessional;
use App\Models\Professional;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MiodottoreDebugOrariCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_fails_with_a_clear_message_when_login_configuration_is_missing(): void
    {
        $professional = Professional::factory()->create();

        config()->set('services.miodottore.login_url', '');
        config()->set('services.miodottore.username', '');
        config()->set('services.miodottore.password', '');

        $this->artisan('miodottore:debug-orari', [
            'professionalId' => $professional->id,
        ])
            ->expectsOutputToContain('Configurazione MioDottore incompleta')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_with_a_clear_message_when_the_professional_url_is_not_configured(): void
    {
        $professional = Professional::factory()->create();

        ExternalProviderProfessional::query()->create([
            'professional_id' => $professional->id,
            'provider' => 'miodottore',
            'external_name' => $professional->full_name,
            'enabled' => true,
        ]);

        config()->set('services.miodottore.login_url', 'https://example.test/login');
        config()->set('services.miodottore.username', 'debug@example.test');
        config()->set('services.miodottore.password', 'secret');

        $this->artisan('miodottore:debug-orari', [
            'professionalId' => $professional->id,
        ])
            ->expectsOutputToContain('URL MioDottore del professionista non configurato.')
            ->assertExitCode(1);
    }
}
