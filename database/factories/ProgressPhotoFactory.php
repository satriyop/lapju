<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProgressPhoto>
 */
class ProgressPhotoFactory extends Factory
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
            'root_task_id' => \App\Models\Task::factory(),
            'user_id' => \App\Models\User::factory(),
            'photo_date' => fake()->date(),
            'file_path' => 'progress-photos/'.fake()->uuid().'.jpg',
            'file_name' => fake()->word().'.jpg',
            'file_size' => fake()->numberBetween(500000, 5000000),
            'mime_type' => 'image/jpeg',
            'width' => fake()->numberBetween(800, 1920),
            'height' => fake()->numberBetween(600, 1080),
            'caption' => fake()->optional()->sentence(),
        ];
    }
}
