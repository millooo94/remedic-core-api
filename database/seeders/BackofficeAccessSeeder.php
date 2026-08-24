<?php

namespace Database\Seeders;

use App\Services\BackofficeAccess\BackofficeAccessSynchronizer;
use Illuminate\Database\Seeder;

class BackofficeAccessSeeder extends Seeder
{
    public function run(BackofficeAccessSynchronizer $synchronizer): void
    {
        $synchronizer->synchronize();
    }
}
