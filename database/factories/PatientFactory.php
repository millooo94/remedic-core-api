<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();
        $phone = '+39'.fake()->numerify('3#########');

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim($lastName.' '.$firstName),
            'birth_date' => fake()->dateTimeBetween('1940-01-01', '2006-12-31')->format('Y-m-d'),
            'year_of_birth' => fake()->numberBetween(1940, 2006),
            'phone' => $phone,
            'email' => fake()->safeEmail(),
            'contactable_sms' => true,
            'contactable_email' => true,
            'excluded_from_campaigns' => false,
            'notes' => null,
        ];
    }
}
