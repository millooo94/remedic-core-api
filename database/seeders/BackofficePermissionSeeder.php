<?php

namespace Database\Seeders;

use App\Services\BackofficeAccess\BackofficeAccessSynchronizer;
use Illuminate\Database\Seeder;

class BackofficePermissionSeeder extends Seeder
{
    public function run(BackofficeAccessSynchronizer $synchronizer): void
    {
        $synchronizer->synchronize();
    }
}
