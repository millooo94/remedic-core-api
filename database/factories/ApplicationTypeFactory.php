<?php

namespace Database\Factories;

use App\Models\ApplicationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApplicationType> */
class ApplicationTypeFactory extends Factory
{
    protected $model = ApplicationType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
