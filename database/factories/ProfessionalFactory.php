<?php

namespace Database\Factories;

use App\Models\Professional;
use App\Models\Specialization;
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
        $areaName = Specialization::query()->inRandomOrder()->value('name')
            ?? 'Cardiologia';

        return [
            'subject_type' => 'individual',
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => null,
            'full_name' => $lastName.' '.$firstName,
            'area_name' => $areaName,
            'email' => fake()->safeEmail(),
            'iban' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }
}
