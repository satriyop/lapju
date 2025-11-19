<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Office;
use App\Models\Partner;
use App\Models\Project;
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

        $partners = Partner::all();

        if ($partners->isEmpty()) {
            $this->command->warn('No partners found! Please run PartnerSeeder first.');

            return;
        }

        // Try to get reporter user for coverage-based seeding
        $reporter = User::where('email', 'babinsa@example.com')->first();

        if ($reporter && $reporter->office) {
            $this->seedProjectsForReporter($reporter, $partners);
        } else {
            $this->command->warn('Reporter user or office not found! Using random seeding.');
            $this->seedRandomProjects($partners);
        }

        $this->command->info('Tasks automatically cloned from templates via ProjectObserver.');
    }

    protected function seedProjectsForReporter(User $reporter, Collection $partners): void
    {
        $reporterOffice = $reporter->office;

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

        $this->command->info("Creating projects for reporter {$reporter->name} at {$reporterOffice->name}");
        $this->command->info("Coverage: {$reporterOffice->coverage_province}, {$reporterOffice->coverage_city}, {$reporterOffice->coverage_district}");
        $this->command->info("Found {$locations->count()} locations in coverage area");

        $this->createProjects($partners, $locations, $reporterOffice, $reporter);

        $this->command->info('Created '.Project::count().' projects assigned to reporter user.');
    }

    protected function seedRandomProjects(Collection $partners): void
    {
        $locations = Location::all();
        $koramils = Office::whereHas('level', fn ($q) => $q->where('level', 4))->get();

        if ($koramils->isEmpty()) {
            $this->command->warn('No Koramil offices found! Please run OfficeSeeder first.');

            return;
        }

        $this->createProjects($partners, $locations, null, null, $koramils);

        $this->command->info('Created '.Project::count().' projects with random office assignments.');
    }

    protected function createProjects(
        Collection $partners,
        Collection $locations,
        ?Office $office = null,
        ?User $reporter = null,
        ?Collection $koramils = null
    ): void {
        foreach ($partners as $partner) {
            $projectCount = rand(1, 2);

            for ($i = 0; $i < $projectCount; $i++) {
                $location = $locations->random();

                $project = Project::create([
                    'name' => 'KOPERASI MERAH PUTIH '.$location->village_name,
                    'description' => 'Proyek pembangunan gedung koperasi untuk meningkatkan kesejahteraan masyarakat melalui ekonomi kerakyatan',
                    'partner_id' => $partner->id,
                    'office_id' => $office?->id ?? $koramils->random()->id,
                    'location_id' => $location->id,
                    'start_date' => '2025-11-01',
                    'end_date' => '2026-01-31',
                    'status' => fake()->randomElement(['planning', 'active', 'completed', 'on_hold']),
                ]);

                // Assign reporter to the project if provided
                if ($reporter) {
                    $project->users()->attach($reporter->id, [
                        'role' => 'reporter',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
