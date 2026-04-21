<?php

namespace Database\Factories;

use App\Models\Professional;
use App\Support\Professionals\ProfessionalAreaOptions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    protected $model = Professional::class;

    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => $lastName.' '.$firstName,
            'area_name' => fake()->randomElement(ProfessionalAreaOptions::values()),
            'email' => fake()->safeEmail(),
            'iban' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }
}
