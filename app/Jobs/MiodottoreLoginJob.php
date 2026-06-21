<?php

namespace App\Jobs;

use App\Models\ExternalProviderLoginSession;
use App\Services\IntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MiodottoreLoginJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public readonly string $sessionToken,
    ) {
        $this->onQueue('miodottore');
    }

    public function handle(IntegrationService $integrationService): void
    {
        $session = ExternalProviderLoginSession::query()
            ->where('provider', IntegrationService::PROVIDER_MIODOTTORE)
            ->where('token', $this->sessionToken)
            ->first();

        Log::info('Miodottore login job started.', [
            'session_token' => $this->sessionToken,
            'session_id' => $session?->id,
            'db_connection' => config('database.default'),
            'db_ok' => $this->dbConnectionOk(),
            'php_binary' => PHP_BINARY,
            'php_ini' => php_ini_loaded_file(),
        ]);

        try {
            $result = $integrationService->runMiodottoreLoginFlow($this->sessionToken);

            Log::info('Miodottore login job completed.', [
                'session_token' => $this->sessionToken,
                'session_id' => $session?->id,
                'success' => $result['success'],
                'message' => $result['message'],
            ]);
        } catch (\Throwable $exception) {
            $this->markFailure($exception->getMessage(), $session);

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $session = ExternalProviderLoginSession::query()
            ->where('provider', IntegrationService::PROVIDER_MIODOTTORE)
            ->where('token', $this->sessionToken)
            ->first();

        $this->markFailure($exception->getMessage(), $session);

        Log::error('Miodottore login job failed.', [
            'session_token' => $this->sessionToken,
            'session_id' => $session?->id,
            'error' => $exception->getMessage(),
        ]);
    }

    private function dbConnectionOk(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function markFailure(string $message, ?ExternalProviderLoginSession $session): void
    {
        if ($session) {
            $session->forceFill([
                'status' => IntegrationService::LOGIN_SESSION_ERROR,
                'completed_at' => now(),
                'last_error' => $message,
            ])->save();
        }

        app(IntegrationService::class)->reconcileMiodottoreAccountAfterFailedLogin($message);
    }
}
