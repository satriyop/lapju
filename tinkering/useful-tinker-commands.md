# Useful Laravel Tinker Commands for LAPJU

This document contains frequently used tinker commands for the LAPJU (Progress Report System) project.

## Quick Reference

```bash
# Run single command
php artisan tinker --execute="YOUR_CODE_HERE"

# Interactive mode
php artisan tinker
```

---

## 1. Data Exploration

### Count Records by Model

```php
// Count all records
php artisan tinker --execute="
echo 'Projects: ' . App\Models\Project::count() . PHP_EOL;
echo 'Tasks: ' . App\Models\Task::count() . PHP_EOL;
echo 'TaskProgress: ' . App\Models\TaskProgress::count() . PHP_EOL;
echo 'Users: ' . App\Models\User::count() . PHP_EOL;
echo 'Offices: ' . App\Models\Office::count() . PHP_EOL;
echo 'Locations: ' . App\Models\Location::count() . PHP_EOL;
echo 'TaskTemplates: ' . App\Models\TaskTemplate::count() . PHP_EOL;
"
```

### List All Projects

```php
php artisan tinker --execute="
\$projects = App\Models\Project::with('office', 'location')->get();
foreach(\$projects as \$p) {
    echo 'ID: ' . \$p->id . ' | ' . \$p->name . ' | Office: ' . (\$p->office->name ?? 'N/A') . PHP_EOL;
}
"
```

### List All Users with Roles

```php
php artisan tinker --execute="
\$users = App\Models\User::with(['roles', 'office'])->get();
foreach(\$users as \$u) {
    \$roles = \$u->roles->pluck('name')->join(', ');
    echo 'ID: ' . \$u->id . ' | ' . \$u->name . ' | Roles: ' . (\$roles ?: 'None') . ' | Office: ' . (\$u->office->name ?? 'N/A') . PHP_EOL;
}
"
```

---

## 2. Task Template Analysis

### List All Root Template Tasks

```php
php artisan tinker --execute="
\$rootTemplates = App\Models\TaskTemplate::whereNull('parent_id')->get();
foreach(\$rootTemplates as \$t) {
    echo 'ID: ' . \$t->id . ' | ' . \$t->name . PHP_EOL;
}
"
```

### Check Template Task Usage Across Projects

```php
php artisan tinker --execute="
\$templateTaskId = 2; // Change this
\$tasks = App\Models\Task::where('template_task_id', \$templateTaskId)
    ->with('project:id,name')
    ->get();

\$template = App\Models\TaskTemplate::find(\$templateTaskId);
echo 'Template: ' . \$template->name . PHP_EOL;
echo 'Used in ' . \$tasks->count() . ' projects:' . PHP_EOL . PHP_EOL;

foreach(\$tasks as \$task) {
    \$progressCount = App\Models\TaskProgress::where('task_id', \$task->id)->count();
    echo '  Project: ' . \$task->project->name . ' (Task ID: ' . \$task->id . ') | Progress entries: ' . \$progressCount . PHP_EOL;
}
"
```

### Find All Leaf Template Tasks

```php
php artisan tinker --execute="
\$leafTemplates = App\Models\TaskTemplate::whereDoesntHave('children')->get();
echo 'Total leaf template tasks: ' . \$leafTemplates->count() . PHP_EOL . PHP_EOL;
\$leafTemplates->take(10)->each(function(\$t) {
    echo 'ID: ' . \$t->id . ' | ' . \$t->name . ' | Weight: ' . \$t->weight . PHP_EOL;
});
"
```

---

## 3. Progress Tracking Analysis

### Get Project Progress Summary

```php
php artisan tinker --execute="
\$projectId = 1; // Change this
\$project = App\Models\Project::find(\$projectId);

if (\$project) {
    echo 'Project: ' . \$project->name . PHP_EOL;
    echo 'Period: ' . \$project->start_date . ' to ' . \$project->end_date . PHP_EOL . PHP_EOL;

    \$totalTasks = App\Models\Task::where('project_id', \$projectId)->count();
    \$leafTasks = App\Models\Task::where('project_id', \$projectId)
        ->whereDoesntHave('children')
        ->count();
    \$tasksWithProgress = App\Models\TaskProgress::where('project_id', \$projectId)
        ->distinct('task_id')
        ->count();

    echo 'Total tasks: ' . \$totalTasks . PHP_EOL;
    echo 'Leaf tasks: ' . \$leafTasks . PHP_EOL;
    echo 'Tasks with progress: ' . \$tasksWithProgress . PHP_EOL;
    echo 'Coverage: ' . round((\$tasksWithProgress / \$leafTasks) * 100, 1) . '%' . PHP_EOL;
}
"
```

### Latest Progress Entries

```php
php artisan tinker --execute="
\$latest = App\Models\TaskProgress::with(['task:id,name', 'project:id,name'])
    ->latest('progress_date')
    ->take(10)
    ->get();

echo 'Latest 10 Progress Entries:' . PHP_EOL . PHP_EOL;
foreach(\$latest as \$p) {
    echo \$p->progress_date . ' | ' . \$p->project->name . ' | ' . \$p->task->name . ' | ' . \$p->percentage . '%' . PHP_EOL;
}
"
```

### Progress by Date Range

```php
php artisan tinker --execute="
\$projectId = 1;
\$startDate = '2025-11-01';
\$endDate = '2025-12-31';

\$progress = App\Models\TaskProgress::where('project_id', \$projectId)
    ->whereBetween('progress_date', [\$startDate, \$endDate])
    ->with('task:id,name')
    ->orderBy('progress_date')
    ->get();

echo 'Progress entries for Project ' . \$projectId . ' (' . \$startDate . ' to ' . \$endDate . '):' . PHP_EOL . PHP_EOL;
foreach(\$progress as \$p) {
    echo \$p->progress_date . ' | ' . \$p->task->name . ' | ' . \$p->percentage . '%' . PHP_EOL;
}
"
```

---

## 4. Office Hierarchy Analysis

### Display Office Hierarchy Tree

```php
php artisan tinker --execute="
\$offices = App\Models\Office::with('level')->orderBy('_lft')->get();

foreach(\$offices as \$office) {
    \$depth = 0;
    \$parent = \$office->parent_id;
    while(\$parent) {
        \$depth++;
        \$p = \$offices->firstWhere('id', \$parent);
        \$parent = \$p ? \$p->parent_id : null;
    }

    \$indent = str_repeat('  ', \$depth);
    \$level = \$office->level ? \$office->level->name : 'No Level';
    echo \$indent . '- ' . \$office->name . ' (' . \$level . ')' . PHP_EOL;
}
"
```

### Count Users by Office

```php
php artisan tinker --execute="
\$offices = App\Models\Office::withCount('users')->get();

echo str_pad('Office', 50) . 'Users' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

foreach(\$offices as \$office) {
    echo str_pad(\$office->name, 50) . \$office->users_count . PHP_EOL;
}
"
```

### Get Office Coverage (Projects per Office)

```php
php artisan tinker --execute="
\$offices = App\Models\Office::withCount('projects')->get();

echo str_pad('Office', 50) . 'Projects' . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

foreach(\$offices as \$office) {
    echo str_pad(\$office->name, 50) . \$office->projects_count . PHP_EOL;
}
"
```

---

## 5. User & Role Management

### List All Roles

```php
php artisan tinker --execute="
\$roles = App\Models\Role::all();
foreach(\$roles as \$role) {
    \$userCount = \$role->users()->count();
    echo 'ID: ' . \$role->id . ' | ' . \$role->name . ' | Users: ' . \$userCount . PHP_EOL;
}
"
```

### Find Users by Role

```php
php artisan tinker --execute="
\$roleName = 'Reporter'; // Change this
\$users = App\Models\User::whereHas('roles', function(\$q) use (\$roleName) {
    \$q->where('name', \$roleName);
})->with('office')->get();

echo 'Users with role: ' . \$roleName . PHP_EOL . PHP_EOL;
foreach(\$users as \$u) {
    echo \$u->name . ' | ' . \$u->phone . ' | Office: ' . (\$u->office->name ?? 'N/A') . PHP_EOL;
}
"
```

### Users Pending Approval

```php
php artisan tinker --execute="
\$pending = App\Models\User::where('is_approved', false)->get();
echo 'Pending approval: ' . \$pending->count() . ' users' . PHP_EOL . PHP_EOL;
foreach(\$pending as \$u) {
    echo \$u->name . ' | ' . \$u->phone . ' | NRP: ' . (\$u->nrp ?? 'N/A') . PHP_EOL;
}
"
```

---

## 6. Settings Management

### List All Settings

```php
php artisan tinker --execute="
\$settings = App\Models\Setting::all();
foreach(\$settings as \$s) {
    echo str_pad(\$s->key, 40) . ' = ' . \$s->value . PHP_EOL;
}
"
```

### Get Specific Setting

```php
php artisan tinker --execute="
echo 'App Name: ' . App\Models\Setting::get('app.name', config('app.name')) . PHP_EOL;
echo 'Default Start Date: ' . App\Models\Setting::get('project.default_start_date', 'Not set') . PHP_EOL;
echo 'Default End Date: ' . App\Models\Setting::get('project.default_end_date', 'Not set') . PHP_EOL;
"
```

### Update Setting

```php
php artisan tinker --execute="
App\Models\Setting::set('app.name', 'LAPJU - Project Progress Tracking');
echo 'Setting updated!' . PHP_EOL;
"
```

---

## 7. Database Structure Inspection

### Check Table Columns

```php
php artisan tinker --execute="
\$table = 'tasks'; // Change this
\$columns = DB::select('PRAGMA table_info(' . \$table . ')');
echo 'Columns in ' . \$table . ':' . PHP_EOL;
foreach(\$columns as \$col) {
    echo '  - ' . \$col->name . ' (' . \$col->type . ')' . PHP_EOL;
}
"
```

### Check Model Relationships

```php
php artisan tinker --execute="
\$task = App\Models\Task::with(['parent', 'children', 'project', 'progress'])->first();
echo 'Task: ' . \$task->name . PHP_EOL;
echo 'Parent: ' . (\$task->parent->name ?? 'None') . PHP_EOL;
echo 'Children: ' . \$task->children->count() . PHP_EOL;
echo 'Project: ' . \$task->project->name . PHP_EOL;
echo 'Progress entries: ' . \$task->progress->count() . PHP_EOL;
"
```

---

## 8. Performance & Query Analysis

### Count N+1 Queries (Use with Laravel Debugbar)

```php
php artisan tinker --execute="
// This will show how many queries are executed
DB::enableQueryLog();

\$projects = App\Models\Project::all();
foreach(\$projects as \$project) {
    echo \$project->office->name . PHP_EOL;
}

\$queries = DB::getQueryLog();
echo PHP_EOL . 'Total queries: ' . count(\$queries) . PHP_EOL;

// Better approach with eager loading
DB::flushQueryLog();

\$projects = App\Models\Project::with('office')->get();
foreach(\$projects as \$project) {
    echo \$project->office->name . PHP_EOL;
}

\$queries = DB::getQueryLog();
echo 'With eager loading: ' . count(\$queries) . ' queries' . PHP_EOL;
"
```

### Find Heavy Tasks (Most Progress Entries)

```php
php artisan tinker --execute="
\$tasks = App\Models\Task::withCount('progress')
    ->orderBy('progress_count', 'desc')
    ->take(10)
    ->get();

echo 'Top 10 tasks with most progress entries:' . PHP_EOL . PHP_EOL;
foreach(\$tasks as \$task) {
    echo \$task->name . ' | Entries: ' . \$task->progress_count . PHP_EOL;
}
"
```

---

## 9. Data Validation & Integrity

### Check Tasks Without Template

```php
php artisan tinker --execute="
\$orphanTasks = App\Models\Task::whereNull('template_task_id')->count();
echo 'Tasks without template_task_id: ' . \$orphanTasks . PHP_EOL;
"
```

### Check Invalid Progress (> 100%)

```php
php artisan tinker --execute="
\$invalid = App\Models\TaskProgress::where('percentage', '>', 100)->get();
echo 'Invalid progress entries (>100%): ' . \$invalid->count() . PHP_EOL;
foreach(\$invalid as \$p) {
    echo '  Task ID: ' . \$p->task_id . ' | ' . \$p->percentage . '%' . PHP_EOL;
}
"
```

### Check Projects Without Tasks

```php
php artisan tinker --execute="
\$projectsWithoutTasks = App\Models\Project::doesntHave('tasks')->get();
echo 'Projects without tasks: ' . \$projectsWithoutTasks->count() . PHP_EOL;
foreach(\$projectsWithoutTasks as \$p) {
    echo '  ' . \$p->name . PHP_EOL;
}
"
```

---

## 10. Quick Data Manipulation

### Create Test User

```php
php artisan tinker --execute="
\$user = App\Models\User::create([
    'name' => 'Test User',
    'phone' => '081234567890',
    'password' => bcrypt('password'),
    'is_approved' => true,
    'is_admin' => false,
]);
echo 'User created with ID: ' . \$user->id . PHP_EOL;
"
```

### Approve All Pending Users

```php
php artisan tinker --execute="
\$count = App\Models\User::where('is_approved', false)->update(['is_approved' => true]);
echo 'Approved ' . \$count . ' users' . PHP_EOL;
"
```

### Delete Test Data

```php
php artisan tinker --execute="
// CAREFUL! This deletes data
\$deleted = App\Models\User::where('name', 'LIKE', 'Test%')->delete();
echo 'Deleted ' . \$deleted . ' test users' . PHP_EOL;
"
```

---

## 11. Advanced Queries

### Calculate Overall Project Progress

```php
php artisan tinker --execute="
\$projectId = 1;
\$project = App\Models\Project::find(\$projectId);

// Get all leaf tasks
\$leafTasks = App\Models\Task::where('project_id', \$projectId)
    ->whereDoesntHave('children')
    ->get();

\$totalWeight = 0;
\$weightedProgress = 0;

foreach(\$leafTasks as \$task) {
    // Get latest progress
    \$latestProgress = App\Models\TaskProgress::where('task_id', \$task->id)
        ->latest('progress_date')
        ->first();

    \$percentage = \$latestProgress ? \$latestProgress->percentage : 0;
    \$weight = \$task->weight > 0 ? \$task->weight : 1;

    \$totalWeight += \$weight;
    \$weightedProgress += (\$percentage * \$weight);
}

\$overallProgress = \$totalWeight > 0 ? round(\$weightedProgress / \$totalWeight, 2) : 0;

echo 'Project: ' . \$project->name . PHP_EOL;
echo 'Leaf tasks: ' . \$leafTasks->count() . PHP_EOL;
echo 'Overall progress: ' . \$overallProgress . '%' . PHP_EOL;
"
```

### Find Projects Behind Schedule

```php
php artisan tinker --execute="
\$today = now();
\$projects = App\Models\Project::where('end_date', '<', \$today)
    ->with('tasks')
    ->get();

echo 'Projects past deadline:' . PHP_EOL . PHP_EOL;

foreach(\$projects as \$project) {
    // Calculate overall progress (simplified)
    \$leafTasks = \$project->tasks->filter(function(\$t) {
        return \$t->children->isEmpty();
    });

    \$tasksWithProgress = 0;
    foreach(\$leafTasks as \$task) {
        if (App\Models\TaskProgress::where('task_id', \$task->id)->exists()) {
            \$tasksWithProgress++;
        }
    }

    \$coverage = \$leafTasks->count() > 0 ? round((\$tasksWithProgress / \$leafTasks->count()) * 100, 1) : 0;

    if(\$coverage < 100) {
        echo \$project->name . ' | Deadline: ' . \$project->end_date . ' | Progress: ' . \$coverage . '%' . PHP_EOL;
    }
}
"
```

---

## 12. Useful Helper Functions

### Create Reusable Tinker Script

Create a file `app/Helpers/TinkerHelpers.php`:

```php
<?php

namespace App\Helpers;

class TinkerHelpers
{
    public static function projectSummary($projectId)
    {
        $project = \App\Models\Project::with(['office', 'location'])->find($projectId);

        if (!$project) {
            return "Project not found";
        }

        $tasks = \App\Models\Task::where('project_id', $projectId)->count();
        $progress = \App\Models\TaskProgress::where('project_id', $projectId)
            ->distinct('task_id')
            ->count();

        return [
            'name' => $project->name,
            'office' => $project->office->name ?? 'N/A',
            'location' => $project->location->name ?? 'N/A',
            'period' => $project->start_date . ' to ' . $project->end_date,
            'total_tasks' => $tasks,
            'tasks_with_progress' => $progress,
        ];
    }
}
```

Then use in tinker:

```php
php artisan tinker --execute="print_r(App\Helpers\TinkerHelpers::projectSummary(1));"
```

---

## Tips & Best Practices

1. **Always use `--execute` for scripting** - Easier to reuse and document
2. **Use `PHP_EOL` for line breaks** - Cross-platform compatible
3. **Eager load relationships** - Prevent N+1 queries with `with()`
4. **Check existence before operations** - Use `if ($model)` checks
5. **Use transactions for bulk operations**:
   ```php
   DB::transaction(function() {
       // Your operations here
   });
   ```
6. **Limit results for large datasets** - Use `take(10)` or `limit(100)`
7. **Use query log for debugging** - `DB::enableQueryLog()` and `DB::getQueryLog()`

---

## Quick Reference Card

| Task | Command |
|------|---------|
| Count all projects | `App\Models\Project::count()` |
| Get latest progress | `App\Models\TaskProgress::latest()->first()` |
| Find by ID | `App\Models\Project::find(1)` |
| Filter records | `App\Models\User::where('is_admin', true)->get()` |
| Eager load | `App\Models\Project::with('tasks')->get()` |
| Count related | `App\Models\Project::withCount('tasks')->get()` |
| Create record | `App\Models\User::create([...])` |
| Update record | `$user->update(['name' => 'New'])` |
| Delete record | `$user->delete()` |

---

**Last Updated:** 2025-11-28
**LAPJU Version:** 1.0
