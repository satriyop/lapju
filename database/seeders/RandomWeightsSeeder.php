<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RandomWeightsSeeder extends Seeder
{
    /**
     * Reseed weights for leaf tasks randomly so they sum to 100.
     */
    public function run(): void
    {
        $this->command->info('Reseeding leaf task weights randomly...');

        // Get all leaf tasks (tasks with no children)
        $leafTasks = Task::whereDoesntHave('children')->get();

        if ($leafTasks->isEmpty()) {
            $this->command->error('No leaf tasks found.');

            return;
        }

        $this->command->info("Found {$leafTasks->count()} leaf tasks.");

        // Reset all task weights to 0 first
        DB::table('tasks')->update(['weight' => 0]);

        // Generate random weights for leaf tasks
        $randomWeights = [];
        $totalRandom = 0;

        foreach ($leafTasks as $task) {
            // Generate random weight between 0.1 and 10
            $weight = mt_rand(10, 1000) / 100; // 0.1 to 10.0
            $randomWeights[$task->id] = $weight;
            $totalRandom += $weight;
        }

        // Normalize to sum to 100
        $newSum = 0;
        $lastTaskId = null;

        foreach ($leafTasks as $task) {
            $normalizedWeight = ($randomWeights[$task->id] / $totalRandom) * 100;
            $normalizedWeight = round($normalizedWeight, 4);

            DB::table('tasks')
                ->where('id', $task->id)
                ->update(['weight' => $normalizedWeight]);

            $newSum += $normalizedWeight;
            $lastTaskId = $task->id;
        }

        // Adjust last task for rounding errors
        if ($lastTaskId) {
            $adjustment = 100 - $newSum;
            $lastWeight = (float) DB::table('tasks')->where('id', $lastTaskId)->value('weight');
            DB::table('tasks')
                ->where('id', $lastTaskId)
                ->update(['weight' => $lastWeight + $adjustment]);
        }

        $finalSum = DB::table('tasks')
            ->whereIn('id', $leafTasks->pluck('id'))
            ->sum('weight');

        $this->command->info("Leaf task weights reseeded.");
        $this->command->info("Sum of leaf task weights: {$finalSum}");
        $this->command->info('Done!');
    }
}
