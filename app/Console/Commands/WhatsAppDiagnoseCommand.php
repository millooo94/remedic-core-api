<?php

namespace App\Console\Commands;

use App\Models\ExternalProviderAccount;
use App\Services\IntegrationService;
use App\Services\Marketing\WhatsAppPuppeteerService;
use Illuminate\Console\Command;

class WhatsAppDiagnoseCommand extends Command
{
    protected $signature = 'whatsapp:diagnose';

    protected $description = 'Mostra lo stato reale del connettore WhatsApp e suggerisce l azione operativa corretta';

    public function handle(
        WhatsAppPuppeteerService $whatsAppPuppeteerService,
        IntegrationService $integrationService,
    ): int {
        $account = ExternalProviderAccount::query()
            ->where('provider', IntegrationService::PROVIDER_WHATSAPP)
            ->first();

        $status = $whatsAppPuppeteerService->status();
        $integration = $integrationService->snapshot(IntegrationService::PROVIDER_WHATSAPP);
        $sessionPath = (string) ($status['session_path'] ?? '');
        $hasSessionPath = $sessionPath !== '' && file_exists($sessionPath);

        $this->info('Diagnostica WhatsApp');
        $this->newLine();
        $this->line('Provider: whatsapp');
        $this->line('Account enabled: '.($account?->enabled ? 'yes' : 'no'));
        $this->line('Account login_status: '.($account?->login_status ?? 'null'));
        $this->line('Session status: '.($integration['session_status'] ?? 'unknown'));
        $this->line('Operational state: '.($integration['operational_state'] ?? 'unknown'));
        $this->line('Can send: '.(($integration['can_send'] ?? false) ? 'yes' : 'no'));
        $this->line('Connector raw state: '.($status['state'] ?? 'unknown'));
        $this->line('Connector ready: '.(($status['ready'] ?? false) ? 'yes' : 'no'));
        $this->line('QR required: '.(($status['qr_required'] ?? false) ? 'yes' : 'no'));
        $this->line('Recovering: '.(($integration['is_recovering'] ?? false) ? 'yes' : 'no'));
        $this->line('Queue depth: '.(string) ($status['queue_depth'] ?? 0));
        $this->line('Phone number: '.(string) ($status['phone_number'] ?? 'n/a'));
        $this->line('Push name: '.(string) ($status['push_name'] ?? 'n/a'));
        $this->line('Process id: '.(string) ($status['process_id'] ?? 'n/a'));
        $this->line('Client generation: '.(string) ($status['client_generation'] ?? 'n/a'));
        $this->line('Session path: '.($sessionPath !== '' ? $sessionPath : 'n/a'));
        $this->line('Session path exists: '.($hasSessionPath ? 'yes' : 'no'));
        $this->line('Local auth session: '.($this->boolLabel($status['has_local_auth_session'] ?? null)));
        $this->line('Persisted session: '.($this->boolLabel($status['has_persisted_session'] ?? null)));
        $this->line('Last connected at: '.(string) ($status['last_connected_at'] ?? 'n/a'));
        $this->line('Last account verification: '.(string) ($account?->last_session_verified_at?->toIso8601String() ?? 'n/a'));
        $this->line('Message: '.(string) ($status['message'] ?? 'n/a'));
        $this->line('Last error: '.(string) (($integration['last_error'] ?? null) ?: ($status['last_error_message'] ?? 'n/a')));
        $this->newLine();
        $this->comment('Suggerimento: '.$this->suggestionFor($integration));

        return ($integration['can_send'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  mixed  $value
     */
    private function boolLabel(mixed $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return $value ? 'yes' : 'no';
    }

    /**
     * @param  array<string, mixed>  $integration
     */
    private function suggestionFor(array $integration): string
    {
        return match ((string) ($integration['operational_state'] ?? 'error')) {
            'ready', 'sending' => 'Canale operativo: puoi inviare messaggi.',
            'qr_required' => 'Apri la UI e scansiona il QR code con WhatsApp.',
            'starting' => 'Attendi l avvio del connettore e ripeti la verifica.',
            'authenticated' => 'Attendi la verifica finale della sessione prima di inviare.',
            'recovering' => 'Il connettore sta recuperando la sessione: se non si stabilizza, rigenera il QR.',
            'disconnected', 'not_configured' => 'Avvia un nuovo collegamento WhatsApp o genera un nuovo QR.',
            default => 'Verifica il connettore, il browser Chromium e i log del servizio WhatsApp.',
        };
    }
}
