<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use Illuminate\Support\Facades\DB;

class TaskTemplateClonerService
{
    /**
     * Clone all task templates to a project.
     *
     * This creates Task records from TaskTemplate records, maintaining
     * the hierarchical structure and relationships.
     */
    public function cloneTemplatesForProject(Project $project): void
    {
        DB::transaction(function () use ($project) {
            // Get all task templates ordered by _lft to maintain hierarchy
            $templates = TaskTemplate::orderBy('_lft')->get();

            if ($templates->isEmpty()) {
                return;
            }

            // Map template IDs to new task IDs to maintain parent relationships
            $templateToTaskMap = [];

            foreach ($templates as $template) {
                // Determine the new parent_id based on the template's parent
                $newParentId = null;
                if ($template->parent_id && isset($templateToTaskMap[$template->parent_id])) {
                    $newParentId = $templateToTaskMap[$template->parent_id];
                }

                // Create the task from the template
                $task = Task::create([
                    'project_id' => $project->id,
                    'template_task_id' => $template->id,
                    'name' => $template->name,
                    'volume' => $template->volume,
                    'unit' => $template->unit,
                    'price' => $template->price,
                    'weight' => $template->weight,
                    'parent_id' => $newParentId,
                    '_lft' => $template->_lft,
                    '_rgt' => $template->_rgt,
                ]);

                // Store the mapping for child tasks
                $templateToTaskMap[$template->id] = $task->id;
            }
        });
    }

    /**
     * Delete all tasks for a project.
     *
     * This is useful when re-seeding or resetting a project's tasks.
     */
    public function deleteProjectTasks(Project $project): void
    {
        Task::where('project_id', $project->id)->delete();
    }
}
