<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'name' => $this->faker->sentence(3),
            'volume' => $this->faker->randomFloat(2, 1, 100),
            'unit' => $this->faker->randomElement(['m', 'm²', 'm³', 'kg', 'unit', 'ls']),
            'weight' => $this->faker->randomFloat(2, 0.1, 10),
            'price' => $this->faker->randomFloat(2, 1000, 100000),
            '_lft' => $counter * 2 - 1,
            '_rgt' => $counter * 2,
            'parent_id' => null,
        ];
    }
}
