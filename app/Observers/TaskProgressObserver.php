<?php

namespace App\Observers;

use App\Models\TaskProgress;
use Carbon\Carbon;

class TaskProgressObserver
{
    /**
     * Handle the TaskProgress "created" event.
     */
    public function created(TaskProgress $taskProgress): void
    {
        // Check if this is the FIRST entry for this task
        $existingCount = TaskProgress::where('task_id', $taskProgress->task_id)
            ->where('project_id', $taskProgress->project_id)
            ->count();

        // Only backfill on first entry (count = 1 includes the just-created entry)
        if ($existingCount === 1) {
            $this->backfillProgress($taskProgress);
        }
    }

    /**
     * Backfill progress entries from project start date to one day before the entered date.
     */
    private function backfillProgress(TaskProgress $taskProgress): void
    {
        $project = $taskProgress->project;

        // Skip if project has no start date
        if (! $project || ! $project->start_date) {
            return;
        }

        $startDate = Carbon::parse($project->start_date);
        $progressDate = Carbon::parse($taskProgress->progress_date);

        // Calculate the last date to backfill (one day before the entered date)
        $endDate = $progressDate->copy()->subDay();

        // Skip if no dates to backfill (progress date is on or before project start)
        if ($endDate->lt($startDate)) {
            return;
        }

        // Calculate total days to backfill (inclusive)
        $totalDays = $startDate->diffInDays($endDate) + 1;

        $entries = [];
        for ($i = 0; $i < $totalDays; $i++) {
            $currentDate = $startDate->copy()->addDays($i);

            // Calculate S-curve percentage for this day
            $percentage = $this->calculateSCurve($i, $totalDays, $taskProgress->percentage);

            $entries[] = [
                'task_id' => $taskProgress->task_id,
                'project_id' => $taskProgress->project_id,
                'user_id' => $taskProgress->user_id,
                'percentage' => $percentage,
                'progress_date' => $currentDate->format('Y-m-d'),
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Bulk insert all backfilled entries
        if (! empty($entries)) {
            TaskProgress::insert($entries);
        }
    }

    /**
     * Calculate S-curve progress percentage for a given day.
     *
     * Uses a quadratic S-curve formula:
     * - First half: slow acceleration (P = 2 * t²)
     * - Second half: deceleration (P = 1 - 2 * (1-t)²)
     *
     * @param  int  $dayIndex  Current day (0-based index)
     * @param  int  $totalDays  Total days in the backfill period
     * @param  float  $maxPercentage  Target percentage at the end of the curve
     * @return float Calculated percentage for this day
     */
    private function calculateSCurve(int $dayIndex, int $totalDays, float $maxPercentage): float
    {
        // Normalize to 0-1 range
        $t = $dayIndex / $totalDays;

        // Quadratic S-curve
        if ($t <= 0.5) {
            // First half: slow growth
            $progress = 2 * pow($t, 2);
        } else {
            // Second half: fast then slow
            $progress = 1 - 2 * pow(1 - $t, 2);
        }

        return round($maxPercentage * $progress, 2);
    }
}
