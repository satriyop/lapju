<?php

namespace Database\Factories;

use App\Models\OfficeLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Office>
 */
class OfficeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'level_id' => OfficeLevel::factory(),
            'name' => fake()->company().' '.fake()->randomElement(['Office', 'Branch', 'Unit']),
            'code' => fake()->unique()->numerify('####'),
            'notes' => fake()->optional()->sentence(),
            '_lft' => 1,
            '_rgt' => 2,
        ];
    }
}
