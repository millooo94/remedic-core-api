<?php

namespace Database\Seeders;

use App\Services\CatalogImportService;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $rows = require database_path('data/service_catalog_dump.php');

        app(CatalogImportService::class)->import($rows, 'seed_catalogo');
    }
}
