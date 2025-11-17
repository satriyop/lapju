<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskHierarchySeeder extends Seeder
{
    /**
     * Current hierarchy state tracking.
     *
     * @var array<string, int|null>
     */
    private array $hierarchyState = [
        'root' => null,
        'parent' => null,
        'sub_parent' => null,
        'child' => null,
        'sub_child' => null,
    ];

    /**
     * Cache for created tasks to avoid duplicates.
     *
     * @var array<string, int>
     */
    private array $taskCache = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Seeding tasks from hierarchical CSV...');

        // Clear existing data
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('task_progress')->truncate();
        DB::table('tasks')->truncate();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->command->info('Cleared existing tasks and progress data.');

        $csvPath = database_path('seeders/data/tasks_data.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSV file not found: {$csvPath}");

            return;
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $this->command->error('Failed to open CSV file.');

            return;
        }

        // Skip header row
        fgetcsv($handle);

        $rowCount = 0;
        $leafTaskCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;

            if (count($row) < 10) {
                $this->command->warn("Row {$rowCount}: Insufficient columns, skipping.");

                continue;
            }

            $leafTaskId = $this->processRow($row);

            if ($leafTaskId) {
                $leafTaskCount++;
            }
        }

        fclose($handle);

        $this->command->info("Processed {$rowCount} rows.");
        $this->command->info("Created {$leafTaskCount} leaf tasks.");

        // Rebuild nested set model
        $this->rebuildNestedSet();

        // Verify results
        $totalTasks = Task::count();
        $totalLeafTasks = Task::whereDoesntHave('children')->count();
        $weightSum = Task::whereDoesntHave('children')->sum('weight');

        $this->command->info("Total tasks created: {$totalTasks}");
        $this->command->info("Total leaf tasks: {$totalLeafTasks}");
        $this->command->info("Sum of leaf task weights: {$weightSum}");
        $this->command->info('Done!');
    }

    /**
     * Process a single CSV row.
     *
     * @param  array<int, string>  $row
     */
    private function processRow(array $row): ?int
    {
        // Parse hierarchy columns
        $rootTask = trim($row[0] ?? '');
        $parentTask = trim($row[1] ?? '');
        $subParentTask = trim($row[2] ?? '');
        $childTask = trim($row[3] ?? '');
        $subChildTask = trim($row[4] ?? '');
        $leafTaskName = trim($row[5] ?? '');

        // Parse data columns
        $volume = $this->parseNumber($row[6] ?? '0');
        $unit = trim($row[7] ?? '');
        $price = $this->parseNumber($row[8] ?? '0');
        $weight = $this->parseNumber($row[9] ?? '0');

        // Skip rows without leaf task name
        if (empty($leafTaskName)) {
            return null;
        }

        // Update hierarchy state - only update if value is not empty
        if (! empty($rootTask)) {
            $this->hierarchyState['root'] = $this->getOrCreateTask($rootTask, null);
            // Reset lower levels when root changes
            $this->hierarchyState['parent'] = null;
            $this->hierarchyState['sub_parent'] = null;
            $this->hierarchyState['child'] = null;
            $this->hierarchyState['sub_child'] = null;
        }

        if (! empty($parentTask)) {
            $this->hierarchyState['parent'] = $this->getOrCreateTask(
                $parentTask,
                $this->hierarchyState['root']
            );
            // Reset lower levels when parent changes
            $this->hierarchyState['sub_parent'] = null;
            $this->hierarchyState['child'] = null;
            $this->hierarchyState['sub_child'] = null;
        }

        if (! empty($subParentTask)) {
            $this->hierarchyState['sub_parent'] = $this->getOrCreateTask(
                $subParentTask,
                $this->hierarchyState['parent'] ?? $this->hierarchyState['root']
            );
            // Reset lower levels
            $this->hierarchyState['child'] = null;
            $this->hierarchyState['sub_child'] = null;
        }

        if (! empty($childTask)) {
            $parentId = $this->hierarchyState['sub_parent']
                ?? $this->hierarchyState['parent']
                ?? $this->hierarchyState['root'];
            $this->hierarchyState['child'] = $this->getOrCreateTask($childTask, $parentId);
            // Reset lower level
            $this->hierarchyState['sub_child'] = null;
        }

        if (! empty($subChildTask)) {
            $parentId = $this->hierarchyState['child']
                ?? $this->hierarchyState['sub_parent']
                ?? $this->hierarchyState['parent']
                ?? $this->hierarchyState['root'];
            $this->hierarchyState['sub_child'] = $this->getOrCreateTask($subChildTask, $parentId);
        }

        // Determine parent for leaf task (deepest non-null hierarchy level)
        $leafParentId = $this->hierarchyState['sub_child']
            ?? $this->hierarchyState['child']
            ?? $this->hierarchyState['sub_parent']
            ?? $this->hierarchyState['parent']
            ?? $this->hierarchyState['root'];

        // Create leaf task with data
        return $this->createLeafTask($leafTaskName, $leafParentId, $volume, $unit, $price, $weight);
    }

    /**
     * Get or create a task by name and parent.
     */
    private function getOrCreateTask(string $name, ?int $parentId): int
    {
        $cacheKey = $name.'|'.$parentId;

        if (isset($this->taskCache[$cacheKey])) {
            return $this->taskCache[$cacheKey];
        }

        $task = Task::create([
            'name' => $name,
            'parent_id' => $parentId,
            'volume' => 0,
            'unit' => null,
            'weight' => 0,
            'price' => 0,
            'total_price' => 0,
        ]);

        $this->taskCache[$cacheKey] = $task->id;

        return $task->id;
    }

    /**
     * Create a leaf task with data.
     */
    private function createLeafTask(
        string $name,
        ?int $parentId,
        float $volume,
        string $unit,
        float $price,
        float $weight
    ): int {
        $totalPrice = $volume * $price;

        $task = Task::create([
            'name' => $name,
            'parent_id' => $parentId,
            'volume' => $volume,
            'unit' => $unit,
            'weight' => $weight,
            'price' => $price,
            'total_price' => $totalPrice,
        ]);

        return $task->id;
    }

    /**
     * Parse a number from string, handling comma separators.
     */
    private function parseNumber(string $value): float
    {
        // Remove thousand separators (commas) and convert to float
        $cleaned = str_replace([',', '"'], '', trim($value));

        return (float) $cleaned;
    }

    /**
     * Rebuild nested set model values (_lft and _rgt).
     */
    private function rebuildNestedSet(): void
    {
        $this->command->info('Rebuilding nested set model...');

        // Get root tasks (no parent)
        $rootTasks = Task::whereNull('parent_id')->orderBy('id')->get();

        $counter = 1;

        foreach ($rootTasks as $task) {
            $counter = $this->rebuildNode($task, $counter);
        }

        $this->command->info('Nested set model rebuilt.');
    }

    /**
     * Recursively rebuild nested set values for a node.
     */
    private function rebuildNode(Task $task, int $counter): int
    {
        $lft = $counter++;

        // Get children
        $children = Task::where('parent_id', $task->id)->orderBy('id')->get();

        foreach ($children as $child) {
            $counter = $this->rebuildNode($child, $counter);
        }

        $rgt = $counter++;

        // Update directly to avoid Eloquent overhead
        DB::table('tasks')
            ->where('id', $task->id)
            ->update([
                '_lft' => $lft,
                '_rgt' => $rgt,
            ]);

        return $counter;
    }
}
