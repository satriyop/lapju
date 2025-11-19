<?php

namespace App\Observers;

use App\Models\Project;
use App\Services\TaskTemplateClonerService;

class ProjectObserver
{
    public function __construct(
        protected TaskTemplateClonerService $clonerService
    ) {}

    /**
     * Handle the Project "created" event.
     *
     * Automatically clone all task templates to the newly created project.
     */
    public function created(Project $project): void
    {
        $this->clonerService->cloneTemplatesForProject($project);
    }
}
