<?php

namespace Database\Seeders;

use App\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class TaskDataSeeder extends Seeder
{
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

        $this->command->info('Reading CSV file...');

        $csv = array_map('str_getcsv', file($csvPath));
        $header = array_shift($csv); // Remove header row

        // Normalize header names (trim spaces)
        $header = array_map('trim', $header);

        $updated = 0;
        $skipped = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($csv as $rowIndex => $row) {
            try {
                // Skip if row doesn't have enough columns
                if (count($row) < count($header)) {
                    $skipped++;

                    continue;
                }

                // Map row to associative array
                $data = array_combine($header, $row);

                // Trim all values
                $data = array_map('trim', $data);

                // Skip if Task contains SQL statements or invalid data
                if (empty($data['Task']) || str_contains($data['Task'], '--') || str_contains($data['Task'], 'ALTER TABLE')) {
                    $skipped++;

                    continue;
                }

                // Skip if Volume is empty (parent tasks)
                if (empty($data['Volume'])) {
                    $skipped++;

                    continue;
                }

                // Normalize task name for matching - strip "- " prefix
                $taskName = trim($data['Task']);
                $taskName = ltrim($taskName, '- '); // Remove leading "- "
                $taskName = trim($taskName);

                // Find task by exact name match (case-insensitive)
                $task = Task::whereRaw('LOWER(name) = ?', [strtolower($taskName)])->first();

                if (! $task) {
                    // Try fuzzy match (remove extra spaces)
                    $normalizedName = preg_replace('/\s+/', ' ', $taskName);
                    $task = Task::whereRaw('LOWER(name) = ?', [strtolower($normalizedName)])->first();
                }

                if ($task) {
                    // Parse numeric values (remove thousand separators)
                    $volume = $this->parseNumber($data['Volume']);
                    $price = $this->parseNumber($data['Price']);
                    $weight = $this->parseNumber($data['Weight']);

                    // Update task
                    $task->update([
                        'volume' => $volume,
                        'unit' => $data['Unit'] ?? null,
                        'price' => $price,
                        'weight' => $weight,
                        // total_price will be auto-calculated by model observer
                    ]);

                    $updated++;
                    $this->command->info("✓ Updated: {$taskName}");
                } else {
                    $notFound++;
                    $this->command->warn("✗ Not found: {$taskName}");
                }
            } catch (\Exception $e) {
                $errors++;
                $this->command->error("Error at row {$rowIndex}: {$e->getMessage()}");
            }
        }

        $this->command->newLine();
        $this->command->info('Summary:');
        $this->command->info("- Updated: {$updated} tasks");
        $this->command->info("- Skipped (parent tasks/invalid rows): {$skipped} rows");
        $this->command->info("- Not found: {$notFound} tasks");
        $this->command->info("- Errors: {$errors} rows");
    }

    /**
     * Parse numeric value from CSV (removes thousand separators and commas)
     */
    private function parseNumber(string $value): float
    {
        // Remove thousand separators (commas, periods, spaces)
        $cleaned = str_replace([',', ' '], '', $value);

        // Convert to float
        return (float) $cleaned;
    }
}
