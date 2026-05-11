<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            BackofficeAccessSeeder::class,
            // ProfessionalSeeder::class,
            // CatalogSeeder::class,
            // ExpenseCategorySeeder::class,
            // ApplicationSettingSeeder::class,
            // ExpenseSeeder::class,
            // PerformanceRecordSeeder::class,
        ]);
    }
}
