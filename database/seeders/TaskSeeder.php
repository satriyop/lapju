<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class TaskSeeder extends Seeder
{
    private int $lft = 1;

    private array $parentStack = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = database_path('seeders/data/tasks_data.csv');

        if (! File::exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");

            return;
        }

        $this->command->info('Reading CSV file and creating tasks...');

        $csv = array_map('str_getcsv', file($csvPath));
        $header = array_shift($csv);
        $header = array_map('trim', $header);

        $created = 0;
        $errors = 0;

        // Column indices
        $levels = [
            0 => 'Root Task',
            1 => 'Parent Task',
            2 => 'Sub Parent Task',
            3 => 'Child Task',
            4 => 'Sub Child Task',
            5 => 'Leaf Task',
        ];

        foreach ($csv as $rowIndex => $row) {
            try {
                if (count($row) < count($header)) {
                    continue;
                }

                $data = array_combine($header, $row);
                $data = array_map('trim', $data);

                // Process each level in order (create parent tasks first)
                $lastParentId = null;

                for ($level = 0; $level <= 5; $level++) {
                    $colName = $levels[$level];
                    $taskName = $data[$colName] ?? '';

                    if (empty($taskName)) {
                        // Use existing parent from stack for this level
                        if (isset($this->parentStack[$level])) {
                            $lastParentId = $this->parentStack[$level];
                        }

                        continue;
                    }

                    // Skip SQL statements or invalid data
                    if (str_contains($taskName, '--') || str_contains($taskName, 'ALTER TABLE')) {
                        continue;
                    }

                    // Determine parent - use the last non-empty level's task
                    $parentId = null;
                    for ($i = $level - 1; $i >= 0; $i--) {
                        if (isset($this->parentStack[$i])) {
                            $parentId = $this->parentStack[$i];
                            break;
                        }
                    }

                    // Check if this task already exists with the same parent
                    $existingTask = Task::where('name', $taskName)
                        ->where('parent_id', $parentId)
                        ->first();

                    if ($existingTask) {
                        // Task exists, just update the stack
                        $this->parentStack[$level] = $existingTask->id;
                        $lastParentId = $existingTask->id;
                        // Clear child levels
                        for ($i = $level + 1; $i <= 5; $i++) {
                            unset($this->parentStack[$i]);
                        }

                        continue;
                    }

                    // Parse numeric values (only for leaf tasks - level 5)
                    $volume = 0;
                    $unit = null;
                    $price = 0;
                    $weight = 0;

                    if ($level === 5 || $this->isLeafTask($data, $level, $levels)) {
                        $volume = ! empty($data['Volume']) ? $this->parseNumber($data['Volume']) : 0;
                        $unit = ! empty($data['Unit']) ? $data['Unit'] : null;
                        $price = ! empty($data['Price']) ? $this->parseNumber($data['Price']) : 0;
                        $weight = ! empty($data['Weight']) ? $this->parseNumber($data['Weight']) : 0;
                    }

                    // Create the task
                    $task = Task::create([
                        'name' => $taskName,
                        'parent_id' => $parentId,
                        'volume' => $volume,
                        'unit' => $unit,
                        'price' => $price,
                        'weight' => $weight,
                        '_lft' => $this->lft++,
                        '_rgt' => $this->lft++,
                    ]);

                    // Update parent stack
                    $this->parentStack[$level] = $task->id;
                    $lastParentId = $task->id;
                    // Clear child levels
                    for ($i = $level + 1; $i <= 5; $i++) {
                        unset($this->parentStack[$i]);
                    }

                    $created++;

                    if ($created % 50 === 0) {
                        $this->command->info("Created {$created} tasks...");
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                $this->command->error("Error at row {$rowIndex}: {$e->getMessage()}");
            }
        }

        // Fix nested set values
        $this->command->info('Rebuilding nested set tree...');
        $this->rebuildTree();

        $this->command->newLine();
        $this->command->info('Summary:');
        $this->command->info("- Created: {$created} tasks");
        $this->command->info("- Errors: {$errors} rows");
    }

    /**
     * Parse numeric value from CSV (removes thousand separators)
     */
    private function parseNumber(string $value): float
    {
        $cleaned = str_replace([',', ' '], '', $value);

        return (float) $cleaned;
    }

    /**
     * Check if this is the deepest task in the row (leaf task)
     */
    private function isLeafTask(array $data, int $currentLevel, array $levels): bool
    {
        // Check if any deeper level has data
        for ($i = $currentLevel + 1; $i <= 5; $i++) {
            $colName = $levels[$i];
            if (! empty($data[$colName])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rebuild the nested set tree structure
     */
    private function rebuildTree(): void
    {
        $this->lft = 1;
        $rootTasks = Task::whereNull('parent_id')->orderBy('id')->get();

        foreach ($rootTasks as $task) {
            $this->rebuildNode($task);
        }
    }

    private function rebuildNode(Task $task): void
    {
        $left = $this->lft++;

        $children = Task::where('parent_id', $task->id)->orderBy('id')->get();
        foreach ($children as $child) {
            $this->rebuildNode($child);
        }

        $right = $this->lft++;

        $task->update([
            '_lft' => $left,
            '_rgt' => $right,
        ]);
    }
}
