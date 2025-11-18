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
     * Seed progress data for PT Agrinas project.
     * Uses project's actual start and end dates with realistic S-curve pattern.
     */
    public function run(): void
    {
        // Find PT Agrinas project
        $project = Project::whereHas('partner', fn ($q) => $q->where('name', 'like', '%AGRINAS%'))->first();

        if (! $project) {
            $this->command->error('PT Agrinas project not found!');

            return;
        }

        // Validate project has dates set
        if (! $project->start_date || ! $project->end_date) {
            $this->command->error('Project does not have start_date and end_date set!');

            return;
        }

        // Get or create a user for the progress entries
        $user = User::first();
        if (! $user) {
            $this->command->error('No user found in database!');

            return;
        }

        // Get all leaf tasks (tasks without children)
        $leafTasks = Task::whereNotIn('id', function ($query) {
            $query->select('parent_id')
                ->from('tasks')
                ->whereNotNull('parent_id')
                ->distinct();
        })->orderBy('_lft')->get();

        if ($leafTasks->isEmpty()) {
            $this->command->error('No leaf tasks found!');

            return;
        }

        $this->command->info("Found {$leafTasks->count()} leaf tasks to seed progress for.");

        // Clear existing progress for this project
        TaskProgress::where('project_id', $project->id)->delete();
        $this->command->info('Cleared existing progress data for this project.');

        // Use project's actual start and end dates
        $startDate = Carbon::parse($project->start_date);
        $endDate = Carbon::parse($project->end_date);

        // Generate weekly progress entries (to avoid too many records)
        $currentDate = $startDate->copy();
        $totalDays = $startDate->diffInDays($endDate);
        $progressEntries = [];

        $this->command->info("Project: {$project->name}");
        $this->command->info("Generating progress from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')} ({$totalDays} days)");

        // Calculate S-curve percentages for each task based on their position
        $taskCount = $leafTasks->count();

        while ($currentDate <= $endDate) {
            $daysPassed = $startDate->diffInDays($currentDate);
            $timeProgress = $daysPassed / max($totalDays, 1); // 0 to 1

            foreach ($leafTasks as $index => $task) {
                // Calculate task-specific timing (earlier tasks start sooner)
                $taskTimingOffset = ($index / $taskCount) * 0.3; // 0 to 0.3 offset
                $adjustedProgress = max(0, ($timeProgress - $taskTimingOffset) / (1 - $taskTimingOffset));

                // Apply S-curve formula (logistic function)
                $percentage = $this->calculateSCurvePercentage($adjustedProgress, $index, $taskCount);

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

            // Move to next week (to reduce data volume)
            $currentDate->addWeek();
        }

        // Insert progress entries in chunks
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

        $this->command->info("Successfully seeded {$totalEntries} progress entries for PT Agrinas project.");

        // Show summary
        $this->showProgressSummary($project->id);
    }

    /**
     * Calculate S-curve percentage using logistic function.
     * This creates the characteristic slow-fast-slow pattern.
     */
    private function calculateSCurvePercentage(float $timeProgress, int $taskIndex, int $totalTasks): float
    {
        if ($timeProgress <= 0) {
            return 0;
        }

        if ($timeProgress >= 1) {
            return 100;
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

        // Add small random variance for realism (-2 to +2)
        $randomVariance = (($taskIndex * 7) % 5) - 2;
        $percentage = max(0, min(100, $percentage + $randomVariance));

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
