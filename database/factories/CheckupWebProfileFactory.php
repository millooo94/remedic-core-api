<?php

namespace Database\Factories;

use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CheckupWebProfile> */
class CheckupWebProfileFactory extends Factory
{
    protected $model = CheckupWebProfile::class;

    public function definition(): array
    {
        return [
            'checkup_id' => Checkup::factory(),
            'public_slug' => Str::slug(fake()->unique()->words(3, true)),
            'short_description' => fake()->sentence(),
            'category_label' => null,
            'is_web_enabled' => false,
            'is_local_seo_enabled' => true,
            'robots' => 'index,follow',
        ];
    }
}
