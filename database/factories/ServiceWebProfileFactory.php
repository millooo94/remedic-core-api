<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceWebProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceWebProfile> */
class ServiceWebProfileFactory extends Factory
{
    protected $model = ServiceWebProfile::class;

    public function definition(): array
    {
        $slug = Str::slug(fake()->unique()->words(3, true));

        return [
            'service_id' => Service::factory(),
            'public_slug' => $slug,
            'short_description' => fake()->sentence(),
            'is_web_enabled' => false,
            'is_local_seo_enabled' => true,
            'robots' => 'index,follow',
        ];
    }
}
