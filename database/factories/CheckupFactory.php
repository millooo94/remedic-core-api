<?php

namespace Database\Factories;

use App\Models\Checkup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checkup>
 */
class CheckupFactory extends Factory
{
    protected $model = Checkup::class;

    public function definition(): array
    {
        return [
            'display_name' => 'Check-up '.fake()->unique()->words(3, true),
            'price_amount' => fake()->numberBetween(80, 500),
            'indicative_duration_minutes' => fake()->numberBetween(30, 240),
            'is_active' => true,
            'organizational_notes' => null,
        ];
    }
}
