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
            'Affitto',
            'Utenze',
            'Software',
            'Marketing',
            'Consulenze',
            'Materiali sanitari',
            'Cancelleria',
            'Attrezzature',
            'Personale',
            'Pulizie',
            'Manutenzione',
            'Professionisti',
            'Altro',
        ];

        foreach ($categories as $categoryName) {
            ExpenseCategory::query()->updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                [
                    'name' => $categoryName,
                    'is_active' => true,
                ],
            );
        }
    }
}
