<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\OfficeLevel;
use Illuminate\Database\Seeder;

class OfficeSeeder extends Seeder
{
    /**
     * Seed offices from CSV file with complete hierarchy.
     */
    public function run(): void
    {
        $this->command->info('Seeding offices from CSV...');

        $csvPath = database_path('seeders/data/offices_export.csv');

        if (! file_exists($csvPath)) {
            $this->command->error('CSV file not found at: '.$csvPath);
            $this->command->warn('Please ensure offices_export.csv exists in database/seeders/data/');

            return;
        }

        // Read CSV file
        $file = fopen($csvPath, 'r');

        // Skip header row
        $header = fgetcsv($file);

        $offices = [];
        $rowCount = 0;

        while (($row = fgetcsv($file)) !== false) {
            // Map CSV columns to array keys
            $officeData = [
                'id' => $row[0] !== '' ? (int) $row[0] : null,
                'parent_id' => $row[1] !== '' ? (int) $row[1] : null,
                'level_id' => (int) $row[2],
                'name' => $row[3],
                'code' => $row[4] !== '' ? $row[4] : null,
                'notes' => $row[5] !== '' ? $row[5] : null,
                '_lft' => (int) $row[6],
                '_rgt' => (int) $row[7],
                'coverage_province' => $row[8] !== '' ? $row[8] : null,
                'coverage_city' => $row[9] !== '' ? $row[9] : null,
                'coverage_district' => $row[10] !== '' ? $row[10] : null,
            ];

            $offices[] = $officeData;
            $rowCount++;
        }

        fclose($file);

        // Insert offices in order (maintaining hierarchy with _lft values)
        foreach ($offices as $officeData) {
            Office::updateOrCreate(
                ['id' => $officeData['id']],
                $officeData
            );
        }

        $this->command->info("Successfully seeded {$rowCount} offices from CSV file.");
        $this->command->info('Office seeding completed successfully!');
        $this->command->newLine();

        $this->showSummary();
    }

    /**
     * Show summary of seeded data.
     */
    private function showSummary(): void
    {
        $this->command->info('Office Summary:');

        $levels = OfficeLevel::orderBy('level')->get();
        $summaryData = [];

        foreach ($levels as $level) {
            $count = Office::where('level_id', $level->id)->count();
            $summaryData[] = [
                'level' => $level->level,
                'name' => $level->name,
                'count' => $count,
            ];
        }

        // Display as table
        $headers = ['Level', 'Name', 'Count'];
        $rows = array_map(fn ($item) => [$item['level'], $item['name'], $item['count']], $summaryData);

        $this->command->table($headers, $rows);

        $total = Office::count();
        $this->command->info("Total offices: {$total}");
    }
}
