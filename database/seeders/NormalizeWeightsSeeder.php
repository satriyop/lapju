<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NormalizeWeightsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Normalizes task weights so they sum to exactly 100.
     */
    public function run(): void
    {
        $this->command->info('Normalizing task weights to sum to 100...');

        // Get current sum of weights
        $currentSum = (float) DB::table('tasks')->sum('weight');

        if ($currentSum == 0) {
            $this->command->error('Total weight is 0, cannot normalize.');

            return;
        }

        $this->command->info("Current sum of weights: {$currentSum}");

        // Get all tasks
        $tasks = DB::table('tasks')->select('id', 'weight', 'volume', 'price')->get();
        $newSum = 0;
        $updated = 0;
        $lastTaskId = null;

        foreach ($tasks as $task) {
            $oldWeight = (float) $task->weight;
            $newWeight = round(($oldWeight / $currentSum) * 100, 4);
            $totalPrice = (float) $task->price * (float) $task->volume;

            DB::table('tasks')
                ->where('id', $task->id)
                ->update([
                    'weight' => $newWeight,
                    'total_price' => $totalPrice,
                ]);

            $newSum += $newWeight;
            $lastTaskId = $task->id;
            $updated++;
        }

        // Adjust the last task to ensure exact sum of 100 (handle rounding errors)
        if ($lastTaskId) {
            $adjustment = 100 - $newSum;
            $lastWeight = (float) DB::table('tasks')->where('id', $lastTaskId)->value('weight');
            DB::table('tasks')
                ->where('id', $lastTaskId)
                ->update(['weight' => $lastWeight + $adjustment]);
        }

        $finalSum = DB::table('tasks')->sum('weight');

        $this->command->info("Updated: {$updated} tasks");
        $this->command->info("New sum of weights: {$finalSum}");
        $this->command->info('Weight normalization completed!');
    }
}
