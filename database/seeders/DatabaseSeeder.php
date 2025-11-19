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

        // Create or update admin user
        $adminUser = User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'is_approved' => true,
                'approved_at' => now(),
                'is_admin' => true,
            ]
        );

        // Seed foundational data first (roles, offices, locations)
        $this->call([
            RoleSeeder::class,
            OfficeLevelSeeder::class,
            OfficeSeeder::class,
            OfficeCoverageSeeder::class,
            TaskTemplateSeeder::class,
            SettingSeeder::class,
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
            ['email' => 'babinsa@example.com'],
            [
                'name' => 'Babinsa',
                'nrp' => '31240001',
                'phone' => '081234567890',
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

        // Now seed projects and progress (which depend on reporter user)
        $this->call([
            ProjectSeeder::class,
            ProgressSeeder::class,
        ]);
    }
}
