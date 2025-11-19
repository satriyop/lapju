<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

class OfficeCoverageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Adding geographic coverage to offices...');

        // Coverage for Kodam
        $this->updateOfficeCoverage('Kodam IV/Diponegoro', [
            'coverage_province' => 'Jawa Tengah',
        ]);

        // Coverage for Korem
        $this->updateOfficeCoverage('Korem 074/Warastratama', [
            'coverage_province' => 'Jawa Tengah',
            'coverage_city' => 'Surakarta',
        ]);

        // Coverage for Kodim
        $kodimCoverage = [
            'Kodim 0723/Klaten' => ['coverage_province' => 'Jawa Tengah', 'coverage_city' => 'Klaten'],
            'Kodim 0724/Boyolali' => ['coverage_province' => 'Jawa Tengah', 'coverage_city' => 'Boyolali'],
            'Kodim 0725/Sragen' => ['coverage_province' => 'Jawa Tengah', 'coverage_city' => 'Sragen'],
            'Kodim 0726/Sukoharjo' => ['coverage_province' => 'Jawa Tengah', 'coverage_city' => 'Sukoharjo'],
            'Kodim 0727/Karanganyar' => ['coverage_province' => 'Jawa Tengah', 'coverage_city' => 'Karanganyar'],
            'Kodim 0728/Wonogiri' => ['coverage_province' => 'Jawa Tengah', 'coverage_city' => 'Wonogiri'],
            'Kodim 0735/Surakarta' => ['coverage_province' => 'Jawa Tengah', 'coverage_city' => 'Surakarta'],
        ];

        foreach ($kodimCoverage as $name => $coverage) {
            $this->updateOfficeCoverage($name, $coverage);
        }

        // Coverage for Koramil (sample - you can add more specific district coverage if needed)
        // For now, we'll update Koramil with city-level coverage based on their parent Kodim
        $this->updateKoramilCoverage();

        $this->command->info('Geographic coverage added successfully!');
    }

    /**
     * Update office coverage by name.
     */
    private function updateOfficeCoverage(string $name, array $coverage): void
    {
        $office = Office::where('name', $name)->first();

        if ($office) {
            $office->update($coverage);
            $this->command->info("  Updated: {$name}");
        }
    }

    /**
     * Update Koramil coverage based on parent Kodim.
     */
    private function updateKoramilCoverage(): void
    {
        $kodims = Office::with('children')->whereHas('level', fn ($q) => $q->where('level', 3))->get();

        foreach ($kodims as $kodim) {
            foreach ($kodim->children as $koramil) {
                // Extract district name from Koramil name
                $district = null;

                // Pattern 1: "Koramil 01/Prambanan" -> "Prambanan"
                if (preg_match('/\d+\/(.+)$/', $koramil->name, $matches)) {
                    $district = 'Kec. '.trim($matches[1]);
                }
                // Pattern 2: "Koramil Klaten Tengah" -> "Klaten Tengah"
                elseif (preg_match('/Koramil\s+(.+)$/', $koramil->name, $matches)) {
                    $district = 'Kec. '.trim($matches[1]);
                }

                $koramil->update([
                    'coverage_province' => $kodim->coverage_province,
                    'coverage_city' => $kodim->coverage_city,
                    'coverage_district' => $district,
                ]);

                if ($district) {
                    $this->command->info("    {$koramil->name} -> {$district}");
                }
            }
        }

        $this->command->info('  Updated all Koramil coverage');
    }
}
