<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Seed foundational data first (needed for office_id)
        $this->call([
            OfficeLevelSeeder::class,
            OfficeSeeder::class,
            OfficeCoverageSeeder::class,
            RoleSeeder::class,
            TaskTemplateSeeder::class,
            SettingSeeder::class,
        ]);

        // Get a Kodim office for admin user
        $kodim = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 3))->first();

        // Create or update admin user (using phone as unique identifier)
        $adminUser = User::updateOrCreate(
            ['phone' => '081234567890'],
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'nrp' => '31240000',
                'office_id' => $kodim?->id,
                'password' => bcrypt('password'),
                'is_approved' => true,
                'approved_at' => now(),
                'is_admin' => true,
            ]
        );

        // Seed locations and partners
        $this->call([
            LocationSeeder::class,
            PartnerSeeder::class,
        ]);

        // Assign Admin role to test@example.com
        $adminRole = \App\Models\Role::where('name', 'Admin')->first();
        if ($adminRole && ! $adminUser->hasRole($adminRole)) {
            $adminUser->roles()->attach($adminRole->id, ['assigned_by' => $adminUser->id]);
            $this->command->info("Admin role assigned to: {$adminUser->email}");
        }

        // Create reporter user BEFORE ProjectSeeder so projects can use reporter's coverage
        $reporterRole = \App\Models\Role::where('name', 'Reporter')->first();

        // Get a Koramil office with geographic coverage (prefer with district, fallback to city coverage)
        $koramil = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 4))
            ->whereNotNull('coverage_district')
            ->first();

        // Fallback to any Koramil with city coverage
        if (! $koramil) {
            $koramil = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 4))
                ->whereNotNull('coverage_city')
                ->first();
        }

        // Last resort: just get any Koramil
        if (! $koramil) {
            $koramil = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 4))
                ->first();
        }

        $reporter = User::updateOrCreate(
            ['phone' => '081234567891'],
            [
                'name' => 'Babinsa',
                'email' => 'babinsa@example.com',
                'nrp' => '31240001',
                'password' => bcrypt('password'),
                'office_id' => $koramil?->id,
                'is_approved' => true,
                'approved_at' => now(),
                'approved_by' => 1,
                'is_admin' => false,
            ]
        );

        if ($reporterRole && ! $reporter->hasRole($reporterRole)) {
            $reporter->roles()->attach($reporterRole->id, ['assigned_by' => 1]);
        }

        $this->command->info("Reporter user created: {$reporter->name} at {$koramil?->name}");

        // Create Manager users for each Kodim office
        $managerRole = \App\Models\Role::where('name', 'Manager')->first();
        $kodimOffices = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 3))
            ->orderBy('name')
            ->get();

        $this->command->info("Creating Manager users for {$kodimOffices->count()} Kodim offices...");

        foreach ($kodimOffices as $index => $kodim) {
            // Extract office code from name (e.g., "Kodim 0723/Klaten" -> "0723")
            preg_match('/(\d{4})/', $kodim->name, $matches);
            $officeCode = $matches[1] ?? str_pad($index + 2, 4, '0', STR_PAD_LEFT);

            // Extract location name (e.g., "Kodim 0723/Klaten" -> "Klaten")
            $locationName = explode('/', $kodim->name)[1] ?? "Kodim {$officeCode}";

            $manager = User::updateOrCreate(
                ['phone' => '0812345670' . ($index + 2)],
                [
                    'name' => "Manager {$locationName}",
                    'email' => "kodim{$officeCode}@example.com",
                    'nrp' => '3124010' . ($index + 2),
                    'office_id' => $kodim->id,
                    'password' => bcrypt('password'),
                    'is_approved' => true,
                    'approved_at' => now(),
                    'approved_by' => 1,
                    'is_admin' => false,
                ]
            );

            if ($managerRole && ! $manager->hasRole($managerRole)) {
                $manager->roles()->attach($managerRole->id, ['assigned_by' => 1]);
            }

            $this->command->info("Manager user created: {$manager->name} at {$kodim->name}");
        }

        // Now seed projects and progress (which depend on reporter user)
        $this->call([
            ProjectSeeder::class,
            ProgressSeeder::class,
        ]);
    }
}
