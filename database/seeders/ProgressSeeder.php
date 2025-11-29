<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProgressSeeder extends Seeder
{
    /**
     * Seed progress data for all PT Agrinas projects.
     * Project 1: 100% completion (full timeline to end_date).
     * Projects 2-5: Proportional completion up to today (<30%, <50%, <70%, <90%).
     */
    public function run(): void
    {
        // Get all PT Agrinas projects ordered by ID
        $projects = Project::whereHas('partner', fn ($q) => $q->where('name', 'like', '%AGRINAS%'))
            ->orderBy('id')
            ->get();

        if ($projects->isEmpty()) {
            $this->command->error('No PT Agrinas projects found!');

            return;
        }

        $this->command->info("Found {$projects->count()} projects to seed progress for.");

        // Get or create a user for the progress entries
        $user = User::first();
        if (! $user) {
            $this->command->error('No user found in database!');

            return;
        }

        // Define completion targets for each project
        $completionTargets = [
            1 => 1.0,   // 100% - Project 1
            2 => 0.30,  // 30% - Project 2
            3 => 0.50,  // 50% - Project 3
            4 => 0.70,  // 70% - Project 4
            5 => 0.90,  // 90% - Project 5
        ];

        foreach ($projects as $projectIndex => $project) {
            $projectNumber = $projectIndex + 1;

            // Validate project has dates set
            if (! $project->start_date || ! $project->end_date) {
                $this->command->warn("Project {$projectNumber} does not have start_date and end_date set! Skipping...");

                continue;
            }

            // Get completion target for this project
            $completionTarget = $completionTargets[$projectNumber] ?? 0.50;

            $this->command->newLine();
            $this->command->info("=== Project {$projectNumber}: {$project->name} (Target: ".($completionTarget * 100).'%) ===');

            $this->seedProjectProgress($project, $user, $completionTarget, $projectNumber);
        }
    }

    /**
     * Seed progress for a single project.
     */
    private function seedProjectProgress(Project $project, User $user, float $completionTarget, int $projectNumber): void
    {
        // Skip progress generation for Projects 2-5 (backfill test scenarios)
        if ($projectNumber >= 2 && $projectNumber <= 5) {
            $this->command->info("Skipping progress for Project {$projectNumber} (S-curve backfill test scenario - no initial progress)");

            return;
        }

        // Get all leaf tasks for this project
        $leafTasks = Task::where('project_id', $project->id)
            ->whereDoesntHave('children')
            ->orderBy('_lft')
            ->get();

        if ($leafTasks->isEmpty()) {
            $this->command->warn('No leaf tasks found for this project!');

            return;
        }

        $this->command->info("Found {$leafTasks->count()} leaf tasks.");

        // Clear existing progress for this project
        TaskProgress::where('project_id', $project->id)->delete();

        // Use project's actual start and end dates
        $startDate = Carbon::parse($project->start_date);
        $projectEndDate = Carbon::parse($project->end_date);
        $today = Carbon::now();

        // Determine end progress date based on project number
        if ($projectNumber === 1) {
            // Project 1: Full timeline to end_date (100%)
            $endProgressDate = $projectEndDate;
        } else {
            // Projects 2-5: Up to today (or project end date if today is after it)
            $endProgressDate = $today->lt($projectEndDate) ? $today : $projectEndDate;
        }

        // Generate daily progress entries
        $currentDate = $startDate->copy();
        $totalDays = $startDate->diffInDays($projectEndDate);
        $progressEntries = [];

        $this->command->info("Generating progress from {$startDate->format('Y-m-d')} to {$endProgressDate->format('Y-m-d')}");

        $taskCount = $leafTasks->count();

        // Track max progress per task to ensure monotonic increase and 100% cap
        $taskMaxProgress = [];

        while ($currentDate <= $endProgressDate) {
            $daysPassed = $startDate->diffInDays($currentDate);
            $timeProgress = $daysPassed / max($totalDays, 1); // 0 to 1 based on total project duration

            foreach ($leafTasks as $index => $task) {
                // Calculate task-specific timing (earlier tasks start sooner)
                $taskTimingOffset = ($index / $taskCount) * 0.3;
                $adjustedProgress = max(0, ($timeProgress - $taskTimingOffset) / (1 - $taskTimingOffset));

                // Apply S-curve formula with completion multiplier
                $percentage = $this->calculateSCurvePercentage($adjustedProgress, $index, $taskCount, $completionTarget);

                // Ensure monotonic increase: never go below previous max for this task
                $taskId = $task->id;
                $previousMax = $taskMaxProgress[$taskId] ?? 0;
                $percentage = max($percentage, $previousMax);

                // Cap at 100% absolute maximum
                $percentage = min(100, $percentage);

                // Update max tracker
                $taskMaxProgress[$taskId] = $percentage;

                // Only save if there's meaningful progress (> 0)
                if ($percentage > 0) {
                    $progressEntries[] = [
                        'task_id' => $task->id,
                        'project_id' => $project->id,
                        'user_id' => $user->id,
                        'percentage' => round($percentage, 2),
                        'progress_date' => $currentDate->copy()->startOfDay(),
                        'notes' => $this->generateProgressNote($percentage),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Move to next day (daily entries for Lapjusik Harian)
            $currentDate->addDay();
        }

        // Insert progress entries in chunks
        if (empty($progressEntries)) {
            $this->command->warn('No progress entries to insert.');

            return;
        }

        $chunks = array_chunk($progressEntries, 500);
        $totalEntries = count($progressEntries);

        $this->command->info("Inserting {$totalEntries} progress entries...");

        $bar = $this->command->getOutput()->createProgressBar(count($chunks));
        $bar->start();

        foreach ($chunks as $chunk) {
            TaskProgress::insert($chunk);
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();

        $this->command->info("Successfully seeded {$totalEntries} progress entries.");

        // Show summary
        $this->showProgressSummary($project->id);
    }

    /**
     * Calculate S-curve percentage using logistic function with completion target.
     * This creates the characteristic slow-fast-slow pattern.
     */
    private function calculateSCurvePercentage(float $timeProgress, int $taskIndex, int $totalTasks, float $completionTarget = 1.0): float
    {
        if ($timeProgress <= 0) {
            return 0;
        }

        if ($timeProgress >= 1) {
            return $completionTarget * 100;
        }

        // Logistic S-curve: y = 100 / (1 + e^(-k*(x-0.5)))
        // k controls steepness, higher = steeper
        $k = 10;
        $midpoint = 0.5;

        // Add some variance based on task position
        $variance = sin($taskIndex * 0.1) * 0.05;
        $adjustedTime = $timeProgress + $variance;
        $adjustedTime = max(0, min(1, $adjustedTime));

        $percentage = 100 / (1 + exp(-$k * ($adjustedTime - $midpoint)));

        // Apply completion target multiplier
        $percentage = $percentage * $completionTarget;

        // Add small random variance for realism (-2 to +2)
        $randomVariance = (($taskIndex * 7) % 5) - 2;
        $percentage = max(0, min($completionTarget * 100, $percentage + $randomVariance));

        return $percentage;
    }

    /**
     * Generate appropriate note based on progress percentage.
     */
    private function generateProgressNote(float $percentage): ?string
    {
        if ($percentage < 10) {
            return 'Pekerjaan baru dimulai';
        } elseif ($percentage < 30) {
            return 'Tahap persiapan material';
        } elseif ($percentage < 50) {
            return 'Pekerjaan sedang berlangsung';
        } elseif ($percentage < 70) {
            return 'Progress sesuai jadwal';
        } elseif ($percentage < 90) {
            return 'Mendekati penyelesaian';
        } elseif ($percentage < 100) {
            return 'Tahap finishing';
        } else {
            return 'Pekerjaan selesai';
        }
    }

    /**
     * Show summary of progress data.
     */
    private function showProgressSummary(int $projectId): void
    {
        $summary = TaskProgress::where('project_id', $projectId)
            ->selectRaw('DATE(progress_date) as date, AVG(percentage) as avg_progress, COUNT(*) as entries')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $this->command->newLine();
        $this->command->info('Progress Summary (Average % per date):');
        $this->command->table(
            ['Date', 'Avg Progress %', 'Entries'],
            $summary->map(fn ($row) => [
                $row->date,
                number_format($row->avg_progress, 2).'%',
                $row->entries,
            ])->toArray()
        );
    }
}
