<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Visita cardiologica',
            'Visita cardiologica di controllo',
            'Visita cardiologica + ECG',
            'Visita ginecologica',
            'Visita ginecologica + Ecografia',
        ]);

        return [
            'category_id' => ServiceCategory::factory(),
            'canonical_name' => $name,
            'display_name' => $name,
            'slug' => Str::slug($name.' '.fake()->unique()->word()),
            'description' => null,
            'default_duration_minutes' => 30,
            'is_active' => true,
            'notes' => null,
        ];
    }
}
