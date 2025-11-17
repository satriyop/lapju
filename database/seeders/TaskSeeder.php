<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sqlPath = base_path('tasks.sql');

        if (!file_exists($sqlPath)) {
            $this->command->error('tasks.sql file not found at: ' . $sqlPath);
            return;
        }

        $sql = file_get_contents($sqlPath);

        preg_match('/INSERT INTO `tasks`[^;]+VALUES\s*(.+);/s', $sql, $matches);

        if (empty($matches[1])) {
            $this->command->error('Could not extract INSERT statements from tasks.sql');
            return;
        }

        $valuesString = $matches[1];

        preg_match_all('/\(([^)]+(?:\)[^,][^)]*)*)\)(?:,|$)/s', $valuesString, $rowMatches);

        foreach ($rowMatches[1] as $rowData) {
            $fields = str_getcsv($rowData, ',', "'");

            if (count($fields) >= 10) {
                $unit = trim($fields[3]);
                $parentId = trim($fields[7]);

                DB::table('tasks')->insert([
                    'id' => (int) $fields[0],
                    'name' => $fields[1],
                    'volume' => (float) $fields[2],
                    'unit' => ($unit === 'NULL' || empty($unit)) ? null : $unit,
                    'weight' => (float) $fields[4],
                    '_lft' => (int) $fields[5],
                    '_rgt' => (int) $fields[6],
                    'parent_id' => ($parentId === 'NULL' || empty($parentId)) ? null : (int) $parentId,
                    'created_at' => $fields[8] === 'NULL' ? null : $fields[8],
                    'updated_at' => $fields[9] === 'NULL' ? null : $fields[9],
                ]);
            }
        }

        $this->command->info('Tasks seeded successfully from tasks.sql');
    }
}
