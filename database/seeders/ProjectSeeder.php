<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Partner;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create projects using existing partners, locations, and offices
        $partners = Partner::all();
        $locations = Location::all();

        // Get Koramil offices (leaf level - level 4)
        $koramilLevel = OfficeLevel::where('level', 4)->first();
        $koramils = Office::where('level_id', $koramilLevel->id)->get();

        if ($koramils->isEmpty()) {
            $this->command->warn('No Koramil offices found! Please run OfficeSeeder first.');

            return;
        }

        // Create projects with pattern "PEMBANGUNAN KOPERASI MERAH PUTIH {PARTNER NAME}"
        foreach ($partners as $partner) {
            // Create 1-2 projects per partner
            $projectCount = rand(1, 2);

            for ($i = 0; $i < $projectCount; $i++) {
                Project::create([
                    'name' => 'PEMBANGUNAN KOPERASI MERAH PUTIH '.$partner->name,
                    'description' => 'Proyek pembangunan gedung koperasi untuk meningkatkan kesejahteraan masyarakat melalui ekonomi kerakyatan',
                    'partner_id' => $partner->id,
                    'office_id' => $koramils->random()->id,
                    'location_id' => $locations->random()->id,
                    'start_date' => '2025-11-01',
                    'end_date' => '2026-01-31',
                    'status' => fake()->randomElement(['planning', 'active', 'completed', 'on_hold']),
                ]);
            }
        }

        $this->command->info('Created '.Project::count().' projects with office assignments.');
    }
}
