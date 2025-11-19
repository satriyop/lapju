<?php

namespace App\Console\Commands;

use App\Models\TaskTemplate;
use Illuminate\Console\Command;

class NormalizeTaskTemplateWeights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'task-templates:normalize-weights';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize task template weights to sum to exactly 100';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Normalizing task template weights...');

        // Get all leaf tasks (tasks without children)
        $leafTasks = TaskTemplate::whereDoesntHave('children')->get();

        if ($leafTasks->isEmpty()) {
            $this->warn('No leaf tasks found.');

            return Command::FAILURE;
        }

        // Calculate current sum
        $currentSum = $leafTasks->sum('weight');
        $this->info("Current weight sum: {$currentSum}");

        if ($currentSum == 0) {
            $this->error('Cannot normalize: current weight sum is 0');

            return Command::FAILURE;
        }

        // Calculate scaling factor
        $scalingFactor = 100 / $currentSum;
        $this->info("Scaling factor: {$scalingFactor}");

        // Normalize each weight
        $updated = 0;
        foreach ($leafTasks as $task) {
            $oldWeight = $task->weight;
            $newWeight = round($task->weight * $scalingFactor, 2);

            if ($oldWeight != $newWeight) {
                $task->weight = $newWeight;
                $task->save();
                $updated++;

                $this->line("  Updated: {$task->name} ({$oldWeight} -> {$newWeight})");
            }
        }

        // Verify new sum
        $newSum = TaskTemplate::whereDoesntHave('children')->sum('weight');
        $this->info("New weight sum: {$newSum}");

        // Handle rounding discrepancy
        $difference = round(100 - $newSum, 2);
        if ($difference != 0) {
            $this->warn("Rounding difference: {$difference}");
            $this->info('Adjusting the task with highest weight to compensate...');

            // Add the difference to the task with the highest weight
            $heaviestTask = TaskTemplate::whereDoesntHave('children')
                ->orderBy('weight', 'desc')
                ->first();

            if ($heaviestTask) {
                $oldWeight = $heaviestTask->weight;
                $heaviestTask->weight = round($heaviestTask->weight + $difference, 2);
                $heaviestTask->save();

                $this->info("  Adjusted: {$heaviestTask->name} ({$oldWeight} -> {$heaviestTask->weight})");
            }
        }

        // Final verification
        $finalSum = TaskTemplate::whereDoesntHave('children')->sum('weight');
        $this->info("Final weight sum: {$finalSum}");

        if (round($finalSum, 2) == 100.00) {
            $this->info("✓ Successfully normalized {$updated} task weights to sum to exactly 100!");

            return Command::SUCCESS;
        } else {
            $this->warn("⚠ Warning: Final sum is {$finalSum}, not exactly 100");

            return Command::FAILURE;
        }
    }
}
