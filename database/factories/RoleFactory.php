<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'description' => fake()->optional()->sentence(),
            'office_level_id' => null,
            'permissions' => [],
            'is_system' => false,
        ];
    }

    /**
     * Indicate that the role is a system role.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    /**
     * Set specific permissions for the role.
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'permissions' => $permissions,
        ]);
    }

    /**
     * Set office level for the role.
     */
    public function forOfficeLevel(int $officeLevelId): static
    {
        return $this->state(fn (array $attributes) => [
            'office_level_id' => $officeLevelId,
        ]);
    }
}
