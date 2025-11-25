<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Delete existing projects (will cascade delete tasks)
        // This allows the ProjectObserver to automatically create tasks from templates
        $existingCount = Project::count();
        if ($existingCount > 0) {
            $this->command->info("Deleting {$existingCount} existing projects and their tasks...");
            Project::query()->delete();
        }

        $partner = Partner::first();

        if (! $partner) {
            $this->command->warn('No partner found! Please run PartnerSeeder first.');

            return;
        }

        // Get all 5 reporter users
        $reporters = User::whereHas('roles', fn ($q) => $q->where('name', 'Reporter'))
            ->with('office')
            ->orderBy('email')
            ->limit(5)
            ->get();

        if ($reporters->count() < 5) {
            $this->command->warn("Only {$reporters->count()} reporters found! Expected 5 reporters.");

            return;
        }

        $this->command->info('Creating 5 projects for PT. AGRINAS, each assigned to different reporter...');

        $this->seedProjectsForReporters($reporters, $partner);

        $this->command->info('Created '.Project::count().' projects. Tasks automatically cloned from templates via ProjectObserver.');
    }

    protected function seedProjectsForReporters(Collection $reporters, Partner $partner): void
    {
        foreach ($reporters as $index => $reporter) {
            $reporterOffice = $reporter->office;

            if (! $reporterOffice) {
                $this->command->warn("Reporter {$reporter->name} has no office assigned! Skipping...");

                continue;
            }

            // Get locations within reporter's coverage area
            $locations = Location::query()
                ->when($reporterOffice->coverage_province, fn ($q) => $q->where('province_name', $reporterOffice->coverage_province))
                ->when($reporterOffice->coverage_city, fn ($q) => $q->where('city_name', $reporterOffice->coverage_city))
                ->when($reporterOffice->coverage_district, fn ($q) => $q->where('district_name', $reporterOffice->coverage_district))
                ->get();

            if ($locations->isEmpty()) {
                $this->command->warn('No locations found in reporter coverage area! Using all locations.');
                $locations = Location::all();
            }

            // Select a random location from the coverage area
            $location = $locations->random();

            // Determine start date based on project index for backfill testing
            // Project 1 (index 0): Nov 1 - Full showcase with 100% completion
            // Projects 2-5 (index 1-4): Staggered starts for testing S-curve backfill
            $startDate = match ($index) {
                0 => Setting::get('project.default_start_date', '2025-11-01'), // Nov 1
                1 => '2025-11-10', // Nov 10 - 16 day gap test
                2 => '2025-11-15', // Nov 15 - 11 day gap test
                3 => '2025-11-20', // Nov 20 - 6 day gap test
                4 => '2025-11-25', // Nov 25 - 1 day gap test
                default => Setting::get('project.default_start_date', '2025-11-01'),
            };

            // Create project for this reporter
            $project = Project::create([
                'name' => 'KOPERASI MERAH PUTIH '.$location->village_name,
                'description' => 'Proyek pembangunan gedung koperasi untuk meningkatkan kesejahteraan masyarakat melalui ekonomi kerakyatan',
                'partner_id' => $partner->id,
                'office_id' => $reporterOffice->id,
                'location_id' => $location->id,
                'start_date' => $startDate,
                'end_date' => Setting::get('project.default_end_date', '2026-01-31'),
                'status' => 'active',
            ]);

            // Assign reporter to the project
            $project->users()->attach($reporter->id, [
                'role' => 'reporter',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->command->info("Created project: {$project->name} (Start: {$startDate}) for {$reporter->name} at {$reporterOffice->name}");
        }
    }
}
