<?php

namespace App\Console\Commands;

use App\Models\ExternalProviderAccount;
use App\Services\IntegrationService;
use App\Services\MiodottoreAccessService;
use Illuminate\Console\Command;

class MiodottoreVerifyAccessCommand extends Command
{
    protected $signature = 'miodottore:verify-access';

    protected $description = 'Verifica la sessione salvata di MioDottore';

    public function handle(MiodottoreAccessService $accessService): int
    {
        $account = ExternalProviderAccount::query()->firstOrCreate(
            ['provider' => IntegrationService::PROVIDER_MIODOTTORE],
            ['label' => 'MioDottore', 'enabled' => false]
        );

        $result = $accessService->verifySavedAccess();
        $resultStatus = (string) ($result['result']['status'] ?? '');

        if ($result['success']) {
            $account->forceFill([
                'login_status' => IntegrationService::STATUS_SESSION_VALID,
                'storage_state_path' => $result['state_path'],
                'last_login_at' => now(),
                'last_session_verified_at' => now(),
                'last_error' => null,
            ])->save();

            $this->info($result['message']);
            if ($result['output_dir'] !== '') {
                $this->line("Artefatti verifica: storage/app/{$result['output_dir']}");
            }

            return self::SUCCESS;
        }

        if ($resultStatus === IntegrationService::STATUS_APP_SELECTION_REQUIRED) {
            $account->forceFill([
                'login_status' => IntegrationService::STATUS_APP_SELECTION_REQUIRED,
                'storage_state_path' => $result['state_path'],
                'last_login_at' => now(),
                'last_session_verified_at' => now(),
                'last_error' => $result['message'],
            ])->save();

            $this->warn($result['message']);
            if ($result['output_dir'] !== '') {
                $this->line("Artefatti verifica: storage/app/{$result['output_dir']}");
            }

            return self::FAILURE;
        }

        $account->forceFill([
            'login_status' => $account->storage_state_path
                ? IntegrationService::STATUS_SESSION_EXPIRED
                : IntegrationService::STATUS_SESSION_MISSING,
            'last_session_verified_at' => now(),
            'last_error' => $result['message'],
        ])->save();

        $this->error($result['message']);
        if ($result['output_dir'] !== '') {
            $this->line("Artefatti verifica: storage/app/{$result['output_dir']}");
        }

        return self::FAILURE;
    }
}
