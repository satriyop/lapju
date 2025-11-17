<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProgressSeeder extends Seeder
{
    /**
     * Seed progress data for all projects.
     */
    public function run(): void
    {
        $this->command->info('Seeding progress data...');

        // Clear existing progress data
        DB::table('task_progress')->truncate();

        $projects = Project::all();
        $leafTasks = Task::whereDoesntHave('children')->get();
        $user = User::first();

        if ($projects->isEmpty()) {
            $this->command->error('No projects found.');

            return;
        }

        if ($leafTasks->isEmpty()) {
            $this->command->error('No leaf tasks found.');

            return;
        }

        if (! $user) {
            $this->command->error('No user found.');

            return;
        }

        $this->command->info("Found {$projects->count()} projects and {$leafTasks->count()} leaf tasks.");

        $totalProgress = 0;

        foreach ($projects as $project) {
            $progressCount = $this->seedProjectProgress($project, $leafTasks, $user);
            $totalProgress += $progressCount;
            $this->command->info("Project '{$project->name}': {$progressCount} progress entries");
        }

        $this->command->info("Total progress entries created: {$totalProgress}");
        $this->command->info('Done!');
    }

    private function seedProjectProgress(Project $project, $leafTasks, User $user): int
    {
        $projectStart = Carbon::parse($project->start_date);
        $projectEnd = Carbon::parse($project->end_date);
        $today = now();

        // Only seed progress up to today or project end date
        $effectiveEndDate = $projectEnd->lessThan($today) ? $projectEnd : $today;

        // If project hasn't started yet, skip
        if ($projectStart->greaterThan($today)) {
            return 0;
        }

        $totalDays = $projectStart->diffInDays($effectiveEndDate);

        if ($totalDays <= 0) {
            return 0;
        }

        $progressEntries = 0;

        // Simulate progress for each leaf task
        foreach ($leafTasks as $task) {
            // Randomly decide task progress pattern
            $completionRate = mt_rand(30, 100) / 100; // 30% to 100% completion
            $startDelay = mt_rand(0, (int) ($totalDays * 0.3)); // Some tasks start later
            $progressSpeed = mt_rand(50, 150) / 100; // Some tasks progress faster

            // Determine how many progress entries to create (weekly updates)
            $intervalDays = $totalDays <= 30 ? 3 : 7;

            $taskStartDate = $projectStart->copy()->addDays($startDelay);
            $currentDate = $taskStartDate->copy();

            $currentProgress = 0;

            while ($currentDate <= $effectiveEndDate && $currentProgress < 100) {
                // Calculate expected progress based on time elapsed from task start
                $daysFromTaskStart = $taskStartDate->diffInDays($currentDate);
                $taskDuration = $totalDays - $startDelay;

                if ($taskDuration > 0 && $daysFromTaskStart >= 0) {
                    $timeProgress = ($daysFromTaskStart / $taskDuration) * 100;
                    // Apply speed factor and completion rate
                    $targetProgress = min($timeProgress * $progressSpeed * $completionRate, 100);

                    // Add some randomness but ensure progress doesn't decrease
                    $randomFactor = mt_rand(-5, 10);
                    $newProgress = max($currentProgress, min($targetProgress + $randomFactor, 100));

                    // Only create entry if progress changed significantly (at least 1%)
                    if ($newProgress - $currentProgress >= 1) {
                        DB::table('task_progress')->insert([
                            'task_id' => $task->id,
                            'project_id' => $project->id,
                            'user_id' => $user->id,
                            'progress_date' => $currentDate->format('Y-m-d'),
                            'percentage' => round($newProgress, 2),
                            'notes' => $this->generateNote($newProgress),
                            'created_at' => $currentDate,
                            'updated_at' => $currentDate,
                        ]);

                        $currentProgress = $newProgress;
                        $progressEntries++;
                    }
                }

                $currentDate->addDays($intervalDays);
            }
        }

        return $progressEntries;
    }

    private function generateNote(float $percentage): ?string
    {
        if ($percentage >= 100) {
            return fake()->randomElement([
                'Task completed',
                'Finished',
                'Done',
                'Completed successfully',
            ]);
        }

        if ($percentage >= 75) {
            return fake()->randomElement([
                'Nearly complete',
                'Final stages',
                'Finishing up',
                null,
                null,
            ]);
        }

        if ($percentage >= 50) {
            return fake()->randomElement([
                'Good progress',
                'On track',
                'Progressing well',
                null,
                null,
                null,
            ]);
        }

        if ($percentage >= 25) {
            return fake()->randomElement([
                'In progress',
                'Work ongoing',
                null,
                null,
                null,
            ]);
        }

        return fake()->randomElement([
            'Started',
            'Beginning work',
            null,
            null,
        ]);
    }
}
