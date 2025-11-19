<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvFile = database_path('seeders/data/locations_export.csv');

        if (! file_exists($csvFile)) {
            $this->command->error('CSV file not found: '.$csvFile);

            return;
        }

        $this->command->info('Importing locations from CSV...');

        // Clear existing locations
        Location::query()->delete();

        $file = fopen($csvFile, 'r');
        $header = fgetcsv($file); // Skip header row

        $imported = 0;
        $rowNumber = 1; // Start at 1 (header is row 1, data starts at row 2)

        while (($row = fgetcsv($file)) !== false) {
            $rowNumber++;

            // Map CSV columns to model fields
            $provinceName = $row[0] ?? null;
            $cityName = $row[1] ?? null;
            $districtName = $row[2] ?? null;
            $villageName = $row[3] ?? null;
            $notes = $row[4] ?? null;

            // Skip if village name is empty
            if (! $villageName) {
                $this->command->warn("  Skipping row {$rowNumber}: missing village name");

                continue;
            }

            // Normalize district name to consistent format
            $districtName = $this->normalizeDistrictName($districtName);

            Location::create([
                'province_name' => $provinceName,
                'city_name' => $cityName,
                'district_name' => $districtName,
                'village_name' => $villageName,
                'notes' => $notes,
            ]);

            $imported++;
        }

        fclose($file);

        $this->command->info("Successfully imported {$imported} locations!");
    }

    /**
     * Normalize district name to consistent format "Kec. District".
     */
    private function normalizeDistrictName(?string $districtName): ?string
    {
        if (! $districtName) {
            return null;
        }

        // Normalize to "Kec. District" format
        if (preg_match('/^Kec\.?\s*(.+)$/i', $districtName, $matches)) {
            return 'Kec. '.trim($matches[1]);
        }

        return $districtName;
    }
}
