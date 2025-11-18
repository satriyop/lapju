<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OfficeLevel>
 */
class OfficeLevelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'level' => fake()->unique()->numberBetween(1, 100),
            'name' => fake()->randomElement(['Kodam', 'Korem', 'Kodim', 'Koramil']),
            'description' => fake()->sentence(),
            'is_default_user_level' => false,
        ];
    }
}
