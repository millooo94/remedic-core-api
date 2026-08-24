<?php

namespace App\Services\BackofficeAccess;

use Illuminate\Support\Facades\Log;
use Throwable;

final class BackofficeAccessReconciler
{
    public function __construct(
        private readonly BackofficeAccessCatalog $catalog,
        private readonly BackofficeAccessSynchronizer $synchronizer,
    ) {}

    public function reconcileIfNeeded(): bool
    {
        try {
            if ($this->synchronizer->isSynchronized()) {
                return true;
            }

            if (! $this->synchronizer->tablesAreAvailable()) {
                Log::error('Backoffice RBAC reconciliation unavailable.', [
                    'phase' => 'table_availability',
                    'catalog' => substr($this->catalog->fingerprint(), 0, 12),
                ]);

                return false;
            }

            Log::warning('Backoffice RBAC drift detected; reconciliation started.', [
                'phase' => 'drift_detection',
                'catalog' => substr($this->catalog->fingerprint(), 0, 12),
            ]);

            $result = $this->synchronizer->synchronize();

            Log::info('Backoffice RBAC reconciliation completed.', [
                'phase' => 'synchronization',
                'permissions' => $result['permissions'],
                'roles' => $result['roles'],
                'legacy_admins_assigned' => $result['legacy_admins_assigned'],
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::error('Backoffice RBAC reconciliation failed.', [
                'phase' => 'synchronization',
                'error_type' => $exception::class,
                'error_code' => (string) $exception->getCode(),
            ]);

            return false;
        }
    }
}
