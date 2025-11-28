<?php

namespace App\Observers;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\TemplateChange;

class TaskTemplateObserver
{
    /**
     * Handle the TaskTemplate "updated" event.
     * Log changes if relevant fields were modified.
     */
    public function updated(TaskTemplate $template): void
    {
        // getOriginal() still contains the old values after update
        $oldValues = $template->getOriginal();
        $newValues = $template->getAttributes();

        // Only log if relevant values changed
        $relevantFields = ['name', 'volume', 'unit', 'weight', 'price'];
        $hasRelevantChange = false;

        foreach ($relevantFields as $field) {
            $oldValue = $oldValues[$field] ?? null;
            $newValue = $newValues[$field] ?? null;

            // Handle type differences (e.g., string "0.00" vs float 0.00)
            if (is_numeric($oldValue) && is_numeric($newValue)) {
                if ((float) $oldValue !== (float) $newValue) {
                    $hasRelevantChange = true;
                    break;
                }
            } elseif ($oldValue !== $newValue) {
                $hasRelevantChange = true;
                break;
            }
        }

        if ($hasRelevantChange && auth()->check()) {
            TemplateChange::create([
                'task_template_id' => $template->id,
                'user_id' => auth()->id(),
                'old_values' => array_intersect_key($oldValues, array_flip($relevantFields)),
                'new_values' => array_intersect_key($newValues, array_flip($relevantFields)),
                'affected_projects_count' => Project::whereHas('tasks', fn ($q) => $q->where('template_task_id', $template->id)
                )->count(),
                'affected_tasks_count' => Task::where('template_task_id', $template->id)->count(),
            ]);
        }
    }
}
