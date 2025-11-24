<?php

namespace Database\Seeders;

use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ProgressPhotoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding progress photos...');

        // Ensure storage directory exists
        Storage::disk('public')->makeDirectory('progress');

        // Get all projects with their root tasks (only projects with tasks from ProjectSeeder)
        // Filter to only include projects that have leaf tasks with progress
        $projects = Project::with(['tasks' => function ($query) {
            $query->whereNull('parent_id')->orderBy('_lft');
        }])
            ->whereHas('tasks', function ($query) {
                // Check if project has any leaf tasks (tasks that are not parents)
                $query->whereNotIn('id', function ($subQuery) {
                    $subQuery->select('parent_id')
                        ->from('tasks as t2')
                        ->whereColumn('t2.project_id', 'tasks.project_id')
                        ->whereNotNull('parent_id')
                        ->distinct();
                })
                    // And those leaf tasks have progress entries
                    ->whereHas('progress');
            })
            ->get();

        if ($projects->isEmpty()) {
            $this->command->warn('No projects with leaf task progress found. Please run ProgressSeeder first.');

            return;
        }

        $this->command->info("Found {$projects->count()} project(s) with leaf task progress to seed photos for.");

        // Get reporter users (who typically upload photos)
        $reporters = User::whereHas('roles', fn ($q) => $q->where('name', 'Reporter'))
            ->get();

        if ($reporters->isEmpty()) {
            $this->command->warn('No reporter users found. Using first available user.');
            $reporters = User::limit(1)->get();

            if ($reporters->isEmpty()) {
                $this->command->error('No users found in database. Please run DatabaseSeeder first.');

                return;
            }
        }

        $this->command->info("Found {$reporters->count()} reporter user(s).");

        $photosCreated = 0;
        $photosPerProject = [];

        // Create photos for the past 7 days for each project
        foreach ($projects as $project) {
            $rootTasks = $project->tasks;

            if ($rootTasks->isEmpty()) {
                $this->command->warn("Project '{$project->name}' has no root tasks. Skipping...");

                continue;
            }

            $this->command->info("Processing project: {$project->name} ({$rootTasks->count()} root tasks)");

            // Filter root tasks to only include those with descendant progress
            $rootTasksWithProgress = $rootTasks->filter(function ($rootTask) {
                return $rootTask->hasAnyDescendantProgress();
            });

            if ($rootTasksWithProgress->isEmpty()) {
                $this->command->warn("  Project '{$project->name}' has no root tasks with progress. Skipping...");

                continue;
            }

            $this->command->info("  Found {$rootTasksWithProgress->count()} root task(s) with progress (out of {$rootTasks->count()})");

            // Select a random reporter for this project
            $reporter = $reporters->random();
            $projectPhotos = 0;

            // Create photos for the past 7 days
            for ($i = 0; $i < 7; $i++) {
                $date = Carbon::today()->subDays($i);

                // Create 1 photo for each root task (simulating real usage)
                // Only create for some root tasks (not all) to be realistic
                $tasksToPhoto = $rootTasksWithProgress->random(min(3, $rootTasksWithProgress->count()));

                foreach ($tasksToPhoto as $rootTask) {
                    // Check if photo already exists
                    $existingPhoto = ProgressPhoto::where([
                        'project_id' => $project->id,
                        'root_task_id' => $rootTask->id,
                        'photo_date' => $date,
                    ])->first();

                    if ($existingPhoto) {
                        continue; // Skip if already exists
                    }

                    // Create placeholder image
                    $imageData = $this->createPlaceholderImage($project->id, $rootTask->id, $date);

                    // Create photo record
                    ProgressPhoto::create([
                        'project_id' => $project->id,
                        'root_task_id' => $rootTask->id,
                        'user_id' => $reporter->id,
                        'photo_date' => $date,
                        'file_path' => $imageData['path'],
                        'file_name' => $imageData['filename'],
                        'file_size' => $imageData['size'],
                        'mime_type' => 'image/jpeg',
                        'width' => 800,
                        'height' => 600,
                        'caption' => $this->generateCaption($rootTask->name, $date),
                        'created_at' => $date->copy()->addHours(rand(8, 17)),
                        'updated_at' => $date->copy()->addHours(rand(8, 17)),
                    ]);

                    $photosCreated++;
                    $projectPhotos++;
                }
            }

            $photosPerProject[$project->id] = $projectPhotos;
            $this->command->info("  Created {$projectPhotos} photos for project '{$project->name}'");
        }

        $this->command->newLine();
        $this->command->info("✓ Successfully created {$photosCreated} progress photos across {$projects->count()} project(s)!");

        // Summary table
        $this->command->table(
            ['Project ID', 'Project Name', 'Photos Created'],
            collect($photosPerProject)->map(function ($count, $id) {
                $project = Project::find($id);

                return [$id, \Illuminate\Support\Str::limit($project->name, 50), $count];
            })->toArray()
        );
    }

    /**
     * Create a placeholder image file.
     */
    private function createPlaceholderImage(int $projectId, int $taskId, Carbon $date): array
    {
        $directory = "progress/{$projectId}/{$date->format('Y-m-d')}";
        $hash = md5($projectId.$taskId.$date->format('Y-m-d').time().rand());
        $filename = "{$projectId}_{$taskId}_{$date->format('Y-m-d')}_{$hash}.jpg";
        $filePath = "{$directory}/{$filename}";

        // Create directory if it doesn't exist
        Storage::disk('public')->makeDirectory($directory);

        // Create a simple placeholder image using GD
        $width = 800;
        $height = 600;
        $image = imagecreatetruecolor($width, $height);

        // Random background color
        $bgColor = imagecolorallocate($image, rand(200, 255), rand(200, 255), rand(200, 255));
        imagefill($image, 0, 0, $bgColor);

        // Add text
        $textColor = imagecolorallocate($image, rand(50, 100), rand(50, 100), rand(50, 100));
        $text = "Progress Photo\nProject: {$projectId}\nTask: {$taskId}\n{$date->format('Y-m-d')}";

        // Simple text rendering (GD built-in font)
        imagestring($image, 5, 10, 10, 'Progress Photo', $textColor);
        imagestring($image, 4, 10, 30, "Project: {$projectId}", $textColor);
        imagestring($image, 4, 10, 50, "Task: {$taskId}", $textColor);
        imagestring($image, 4, 10, 70, $date->format('Y-m-d'), $textColor);

        // Save image
        $fullPath = storage_path("app/public/{$filePath}");
        imagejpeg($image, $fullPath, 85);
        imagedestroy($image);

        // Get file size
        $fileSize = filesize($fullPath);

        return [
            'path' => $filePath,
            'filename' => $filename,
            'size' => $fileSize,
        ];
    }

    /**
     * Generate a realistic caption for the photo.
     */
    private function generateCaption(string $taskName, Carbon $date): ?string
    {
        $captions = [
            "Progress update for {$taskName}",
            "Work in progress - {$date->format('M d')}",
            'Current state of work',
            'Site documentation',
            "{$taskName} - {$date->format('l')}",
            null, // Some photos without caption
            'Field inspection photo',
            'Construction progress',
        ];

        return $captions[array_rand($captions)];
    }
}
