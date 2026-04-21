<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Affitto', 'type' => 'fixed'],
            ['name' => 'Utenze', 'type' => 'fixed'],
            ['name' => 'Software', 'type' => 'fixed'],
            ['name' => 'Marketing', 'type' => 'variable'],
            ['name' => 'Consulenze', 'type' => 'variable'],
            ['name' => 'Materiali sanitari', 'type' => 'variable'],
            ['name' => 'Cancelleria', 'type' => 'variable'],
            ['name' => 'Attrezzature', 'type' => 'variable'],
            ['name' => 'Personale', 'type' => 'fixed'],
            ['name' => 'Pulizie', 'type' => 'fixed'],
            ['name' => 'Manutenzione', 'type' => 'variable'],
            ['name' => 'Altro', 'type' => 'variable'],
        ];

        foreach ($categories as $index => $category) {
            ExpenseCategory::query()->updateOrCreate(
                ['slug' => Str::slug($category['name'])],
                [
                    'name' => $category['name'],
                    'type' => $category['type'],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
