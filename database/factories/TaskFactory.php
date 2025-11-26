<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => \App\Models\Project::factory(),
            'name' => fake()->sentence(3),
            'volume' => fake()->randomFloat(2, 0, 99999),
            'unit' => fake()->optional()->randomElement(['m3', 'm2', 'm', 'kg', 'pcs', 'unit']),
            'weight' => fake()->randomFloat(2, 0, 999),
            'price' => fake()->randomFloat(2, 1, 9999),
            '_lft' => 0,
            '_rgt' => 0,
            'parent_id' => null,
        ];
    }
}
