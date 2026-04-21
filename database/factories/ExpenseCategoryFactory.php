<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Affitto', 'Marketing', 'Software']);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => $name === 'Marketing' ? 'variable' : 'fixed',
            'is_active' => true,
            'sort_order' => 1,
        ];
    }
}
