<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationGeocodingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Uses pre-geocoded data from LocationLatLong.csv file.
     * The coordinates were originally obtained from OpenStreetMap Nominatim API.
     */
    public function run(): void
    {
        $csvPath = database_path('seeders/data/LocationLatLong.csv');

        if (! file_exists($csvPath)) {
            $this->command->error("CSV file not found: {$csvPath}");
            $this->command->info('Please ensure LocationLatLong.csv exists in database/seeders/data/');

            return;
        }

        $coordinates = $this->loadCoordinatesFromCsv($csvPath);

        if (empty($coordinates)) {
            $this->command->error('No coordinates found in CSV file.');

            return;
        }

        $this->command->info('Loaded '.count($coordinates).' coordinates from CSV file.');

        $locations = Location::all();
        $this->command->info("Processing {$locations->count()} locations...");
        $this->command->newLine();

        $updated = 0;
        $skipped = 0;
        $bar = $this->command->getOutput()->createProgressBar($locations->count());
        $bar->start();

        foreach ($locations as $location) {
            if (isset($coordinates[$location->id])) {
                $coords = $coordinates[$location->id];
                $location->update([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ]);
                $updated++;
            } else {
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info("Updated: {$updated} locations");

        if ($skipped > 0) {
            $this->command->warn("Skipped: {$skipped} locations (no coordinates in CSV)");
        }
    }

    /**
     * Load coordinates from CSV file into an associative array keyed by location ID.
     */
    private function loadCoordinatesFromCsv(string $path): array
    {
        $coordinates = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        // Skip header row
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 6) {
                $id = (int) $row[0];
                $latitude = $row[4] !== '' ? (float) $row[4] : null;
                $longitude = $row[5] !== '' ? (float) $row[5] : null;

                if ($latitude !== null && $longitude !== null) {
                    $coordinates[$id] = [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ];
                }
            }
        }

        fclose($handle);

        return $coordinates;
    }
}
