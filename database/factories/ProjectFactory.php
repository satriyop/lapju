<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-1 year', '+1 month');
        $endDate = fake()->dateTimeBetween($startDate, '+2 years');

        return [
            'name' => fake()->catchPhrase(),
            'description' => fake()->optional()->paragraph(),
            'customer_id' => \App\Models\Customer::factory(),
            'location_id' => \App\Models\Location::factory(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => fake()->randomElement(['planning', 'active', 'completed', 'on_hold']),
        ];
    }
}
