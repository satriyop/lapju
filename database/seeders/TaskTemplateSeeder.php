<?php

namespace Database\Seeders;

use App\Models\TaskTemplate;
use Illuminate\Database\Seeder;

class TaskTemplateSeeder extends Seeder
{
    /**
     * Seed task templates from tasks_data.csv with automatic hierarchy building.
     */
    public function run(): void
    {
        $csvFile = database_path('seeders/data/tasks_data.csv');

        if (! file_exists($csvFile)) {
            $this->command->error('CSV file not found: '.$csvFile);

            return;
        }

        $this->command->info('Importing task templates from tasks_data.csv...');

        // Clear existing templates
        TaskTemplate::query()->delete();

        $file = fopen($csvFile, 'r');
        $header = fgetcsv($file); // Skip header row

        $counter = 1;
        $hierarchy = [];
        $rowNumber = 1;
        $rowsProcessed = 0;
        $tasksCreated = 0;

        // Track previous row values to handle empty cells (inherit from above)
        $prevRoot = null;
        $prevParent = null;
        $prevSubParent = null;
        $prevChild = null;
        $prevSubChild = null;

        while (($row = fgetcsv($file)) !== false) {
            $rowNumber++;
            $rowsProcessed++;

            // Map CSV columns - use previous value if current cell is empty
            $rootTask = ! empty($row[0]) ? $row[0] : $prevRoot;
            $parentTask = ! empty($row[1]) ? $row[1] : $prevParent;
            $subParentTask = ! empty($row[2]) ? $row[2] : $prevSubParent;
            $childTask = ! empty($row[3]) ? $row[3] : $prevChild;
            $subChildTask = ! empty($row[4]) ? $row[4] : $prevSubChild;
            $leafTask = $row[5] ?? null;
            $volume = $this->parseNumber($row[6] ?? 0);
            $unit = $row[7] ?? null;
            $price = $this->parseNumber($row[8] ?? 0);
            $weight = $this->parseNumber($row[9] ?? 0);

            // Update previous values for next iteration
            $prevRoot = $rootTask;
            $prevParent = $parentTask;
            $prevSubParent = $subParentTask;
            $prevChild = $childTask;
            $prevSubChild = $subChildTask;

            // Determine task name from deepest filled column
            $taskName = $leafTask ?: $subChildTask ?: $childTask ?: $subParentTask ?: $parentTask ?: $rootTask;

            if (! $taskName) {
                $this->command->warn("  Skipping empty row {$rowNumber}");

                continue;
            }

            // Build parent hierarchy path (all columns except the task name column)
            $parentHierarchy = [];

            if ($rootTask && $taskName !== $rootTask) {
                $parentHierarchy[] = $rootTask;
            }
            if ($parentTask && $taskName !== $parentTask) {
                $parentHierarchy[] = $parentTask;
            }
            if ($subParentTask && $taskName !== $subParentTask) {
                $parentHierarchy[] = $subParentTask;
            }
            if ($childTask && $taskName !== $childTask) {
                $parentHierarchy[] = $childTask;
            }
            if ($subChildTask && $taskName !== $subChildTask) {
                $parentHierarchy[] = $subChildTask;
            }

            // Ensure all parent nodes exist in the hierarchy
            $currentParentId = null;
            $pathSoFar = [];

            foreach ($parentHierarchy as $levelName) {
                $pathSoFar[] = $levelName;
                $hierarchyKey = implode('|', $pathSoFar);

                // Check if this node already exists
                if (! isset($hierarchy[$hierarchyKey])) {
                    // Create parent/container node
                    $parentNode = TaskTemplate::create([
                        'name' => $levelName,
                        'volume' => 0,
                        'unit' => null,
                        'price' => 0,
                        'weight' => 0,
                        'parent_id' => $currentParentId,
                        '_lft' => $counter++,
                        '_rgt' => $counter++,
                    ]);

                    $hierarchy[$hierarchyKey] = $parentNode->id;
                    $this->command->info("  Created parent: {$levelName}");
                }

                $currentParentId = $hierarchy[$hierarchyKey];
            }

            // Create the actual task (every row creates one task)
            $template = TaskTemplate::create([
                'name' => $taskName,
                'volume' => $volume,
                'unit' => $unit,
                'price' => $price,
                'weight' => $weight,
                'parent_id' => $currentParentId,
                '_lft' => $counter++,
                '_rgt' => $counter++,
            ]);

            $tasksCreated++;
            $this->command->info("  Created task (row {$rowNumber}): {$taskName}");
        }

        fclose($file);

        $totalCount = TaskTemplate::count();
        $autoCreatedParents = $totalCount - $tasksCreated;

        $this->command->info("CSV rows processed: {$rowsProcessed}");
        $this->command->info("Successfully imported {$totalCount} total nodes!");
        $this->command->info("  - {$autoCreatedParents} parent/container nodes (auto-created)");
        $this->command->info("  - {$tasksCreated} task templates (from CSV)");

        // Rebuild nested set values
        $this->command->info('Rebuilding nested set values...');
        $this->rebuildNestedSetValues();
        $this->command->info('Nested set values rebuilt successfully!');
    }

    /**
     * Rebuild nested set values for the entire tree.
     */
    private function rebuildNestedSetValues(): void
    {
        $counter = 1;

        // Get all root nodes (nodes without parents)
        $rootNodes = TaskTemplate::whereNull('parent_id')->get();

        foreach ($rootNodes as $root) {
            $counter = $this->rebuildNode($root, $counter);
        }
    }

    /**
     * Recursively rebuild nested set values for a node and its descendants.
     */
    private function rebuildNode(TaskTemplate $node, int $counter): int
    {
        // Set left value
        $node->_lft = $counter++;

        // Get all children of this node
        $children = TaskTemplate::where('parent_id', $node->id)->get();

        // Recursively process each child
        foreach ($children as $child) {
            $counter = $this->rebuildNode($child, $counter);
        }

        // Set right value
        $node->_rgt = $counter++;

        // Save the node with new values
        $node->save();

        return $counter;
    }

    /**
     * Parse numeric value from CSV (handles comma thousands separator).
     */
    private function parseNumber(?string $value): float
    {
        if (! $value) {
            return 0;
        }

        // Remove quotes and thousands separator, then convert to float
        $cleaned = str_replace(['"', ','], '', $value);

        return (float) $cleaned;
    }
}
