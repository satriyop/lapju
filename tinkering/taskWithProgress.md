   # Progress Data Structure
      php artisan tinker --execute="
   echo \"\\n=== EXPLAINING THE DATA STRUCTURE ===\\n\\n\";

   // Let's use template_task_id = 2 as an example
   \$templateTaskId = 2;
   \$template = App\Models\TaskTemplate::find(\$templateTaskId);

   echo \"Template Task: {\$template->name} (ID: {\$templateTaskId})\\n\";
   echo str_repeat('=', 100) . \"\\n\\n\";

   // Get ALL task instances created from this template
   \$tasks = App\Models\Task::where('template_task_id', \$templateTaskId)
       ->with('project:id,name')
       ->get();

   echo \"This template was used to create \" . \$tasks->count() . \" DIFFERENT task instances:\\n\\n\";

   foreach(\$tasks as \$task) {
       echo \"Task ID: {\$task->id} | Project ID: {\$task->project_id} | Project: {\$task->project->name}\\n\";

       // Check if THIS specific task has progress
       \$progressCount = App\Models\TaskProgress::where('task_id', \$task->id)->count();
       echo \"  → Progress entries: {\$progressCount}\\n\";

       if (\$progressCount > 0) {
           \$latestProgress = App\Models\TaskProgress::where('task_id', \$task->id)
               ->latest('progress_date')
               ->first();
           echo \"  → Latest progress: {\$latestProgress->percentage}% on {\$latestProgress->progress_date}\\n\";
           echo \"  → project_id in progress table: {\$latestProgress->project_id}\\n\";
       }
       echo \"\\n\";
   }

   echo \"\\n=== KEY INSIGHT ===\\n\";
   echo \"Each Task record is UNIQUE and belongs to ONE specific project.\\n\";
   echo \"The 'template_task_id' just indicates which template was used to create it.\\n\";
   echo \"Progress is ALWAYS tracked per Task ID, which inherently means per project.\\n\";
   echo \"\\nYou CANNOT track progress without a project context because:\\n\";
   echo \"  1. Task.id is unique (different for each project)\\n\";
   echo \"  2. TaskProgress.task_id references a specific Task.id\\n\";
   echo \"  3. TaskProgress.project_id explicitly stores which project\\n\";
   "
   
   # Task Template Report
   php artisan tinker --execute="
   echo \"\\n=== COMPREHENSIVE TEMPLATE TASK PROGRESS ANALYSIS ===\\n\\n\";

   // Get all template tasks (both parent and leaf)
   \$allTemplateTasks = App\Models\TaskTemplate::orderBy('_lft')->get();

   // Analyze each template task
   \$results = [];
   foreach (\$allTemplateTasks as \$templateTask) {
       // Get all tasks created from this template task
       \$tasksFromTemplate = App\Models\Task::where('template_task_id', \$templateTask->id)->get();

       if (\$tasksFromTemplate->isEmpty()) {
           continue;
       }

       // Identify leaf tasks
       \$leafTasks = \$tasksFromTemplate->filter(function(\$task) {
           return App\Models\Task::where('parent_id', \$task->id)->count() === 0;
       });

       // Skip if no leaf tasks
       if (\$leafTasks->isEmpty()) {
           continue;
       }

       // Count leaf tasks with progress
       \$leafTaskIds = \$leafTasks->pluck('id')->toArray();
       \$withProgressCount = App\Models\TaskProgress::whereIn('task_id', \$leafTaskIds)
           ->distinct('task_id')
           ->count();

       // Only include template tasks that have progress data
       if (\$withProgressCount > 0) {
           \$results[] = [
               'id' => \$templateTask->id,
               'name' => \$templateTask->name,
               'leaf_count' => \$leafTasks->count(),
               'with_progress' => \$withProgressCount,
               'coverage' => round((\$withProgressCount / \$leafTasks->count()) * 100, 1)
           ];
       }
   }

   echo \"Found \" . count(\$results) . \" template tasks with progress data:\\n\\n\";
   echo str_pad('ID', 5) . str_pad('Leaf Tasks', 12) . str_pad('With Progress', 15) . str_pad('Coverage', 12) . \"Template Task Name\\n\";
   echo str_repeat('-', 120) . \"\\n\";

   foreach (\$results as \$result) {
       echo str_pad(\$result['id'], 5);
       echo str_pad(\$result['leaf_count'], 12);
       echo str_pad(\$result['with_progress'], 15);
       echo str_pad(\$result['coverage'] . '%', 12);
       echo \$result['name'] . \"\\n\";
   }

   // Summary statistics
   echo \"\\n=== SUMMARY ===\\n\";
   \$totalLeafTasks = array_sum(array_column(\$results, 'leaf_count'));
   \$totalWithProgress = array_sum(array_column(\$results, 'with_progress'));
   echo \"Total leaf tasks: {\$totalLeafTasks}\\n\";
   echo \"Leaf tasks with progress: {\$totalWithProgress}\\n\";
   echo \"Overall coverage: \" . round((\$totalWithProgress / \$totalLeafTasks) * 100, 1) . \"%\\n\";
   "


# Quick
php artisan tinker --execute="
  \$templateTaskId = 2; // Change this ID
  \$tasks = App\Models\Task::where('template_task_id', \$templateTaskId)->get();
  \$leafTasks = \$tasks->filter(fn(\$t) => App\Models\Task::where('parent_id', \$t->id)->count() === 0);
  \$withProgress = App\Models\TaskProgress::whereIn('task_id', \$leafTasks->pluck('id'))->distinct('task_id')->count();
  echo \"Template Task ID {\$templateTaskId}: {\$leafTasks->count()} leaf tasks, {\$withProgress} with progress (\".round((\$withProgress/\$leafTasks->count())*100, 
  1).\"%)\n\";
  "