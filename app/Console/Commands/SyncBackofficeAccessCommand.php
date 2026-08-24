<?php

namespace App\Console\Commands;

use App\Services\BackofficeAccess\BackofficeAccessSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class SyncBackofficeAccessCommand extends Command
{
    protected $signature = 'backoffice:sync-access';

    protected $description = 'Synchronize the canonical backoffice roles and permissions';

    public function handle(BackofficeAccessSynchronizer $synchronizer): int
    {
        try {
            $result = $synchronizer->synchronize();
        } catch (Throwable $exception) {
            $this->error('Backoffice RBAC synchronization failed ('.$exception::class.').');

            return self::FAILURE;
        }

        $this->info($result['changed']
            ? 'Backoffice RBAC synchronized.'
            : 'Backoffice RBAC already synchronized.');
        $this->line(sprintf(
            'Permissions: %d; roles: %d; role-permission links: %d; primary admin assignments: %d; legacy admin assignments: %d.',
            $result['permissions'],
            $result['roles'],
            $result['role_permissions'],
            $result['primary_admin_assigned'],
            $result['legacy_admins_assigned'],
        ));

        return self::SUCCESS;
    }
}
