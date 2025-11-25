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
            ['phone' => '08123456789'],
            [
                'name' => 'Admin User',
                'email' => 'admin@korem074.com',
                'nrp' => '31240000',
                // 'office_id' => $kodim?->id,
                'password' => bcrypt('K0r3m@074'),
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

        // Create Kodim Admin users for each Kodim office FIRST
        $kodimRole = \App\Models\Role::where('name', 'Kodim Admin')->first();
        $kodimOffices = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 3))
            ->orderBy('name')
            ->get();

        $this->command->info("Creating Kodim users for {$kodimOffices->count()} Kodim offices...");

        foreach ($kodimOffices as $index => $kodim) {
            // Extract office code from name (e.g., "Kodim 0723/Klaten" -> "0723")
            preg_match('/(\d{4})/', $kodim->name, $matches);
            $officeCode = $matches[1] ?? str_pad($index + 2, 4, '0', STR_PAD_LEFT);

            // Extract location name (e.g., "Kodim 0723/Klaten" -> "Klaten")
            $locationName = explode('/', $kodim->name)[1] ?? "Kodim {$officeCode}";

            $kodimAdmin = User::updateOrCreate(
                ['phone' => $officeCode],
                [
                    'name' => "Admin Kodim {$locationName}",
                    'email' => "kodim{$officeCode}@korem074.com",
                    'nrp' => '3124010'.($index + 2),
                    'office_id' => $kodim->id,
                    'password' => bcrypt('password'),
                    'is_approved' => true,
                    'approved_at' => now(),
                    'approved_by' => 1,
                    'is_admin' => false,
                ]
            );

            if ($kodimRole && ! $kodimAdmin->hasRole($kodimRole)) {
                $kodimAdmin->roles()->attach($kodimRole->id, ['assigned_by' => 1]);
            }

            $this->command->info("Admin Kodim user created: {$kodimAdmin->name} at {$kodim->name}");
        }

        // Get first 3 Kodims to create Koramil Admins and Reporters under them
        $selectedKodims = $kodimOffices->take(3);

        // Create Koramil Admin users - one for each selected Kodim (from first Koramil under each Kodim)
        $koramilAdminRole = \App\Models\Role::where('name', 'Koramil Admin')->first();
        $this->command->info('Creating Koramil Admin users (one per Kodim)...');

        $adminKoramilIds = [];
        foreach ($selectedKodims as $index => $kodim) {
            // Get first Koramil under this Kodim with coverage
            $koramil = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 4))
                ->where('parent_id', $kodim->id)
                ->whereNotNull('coverage_district')
                ->orderBy('name')
                ->first();

            // Fallback to any Koramil under this Kodim
            if (! $koramil) {
                $koramil = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 4))
                    ->where('parent_id', $kodim->id)
                    ->orderBy('name')
                    ->first();
            }

            if ($koramil) {
                $adminKoramilIds[] = $koramil->id;

                $koramilAdmin = User::updateOrCreate(
                    ['phone' => '0812345680'.($index + 1)],
                    [
                        'name' => "Admin {$koramil->name}",
                        'email' => 'koramil.admin'.($index + 1).'@korem074.com',
                        'nrp' => '3124020'.($index + 1),
                        'office_id' => $koramil->id,
                        'password' => bcrypt('password'),
                        'is_approved' => true,
                        'approved_at' => now(),
                        'approved_by' => 1,
                        'is_admin' => false,
                    ]
                );

                if ($koramilAdminRole && ! $koramilAdmin->hasRole($koramilAdminRole)) {
                    $koramilAdmin->roles()->attach($koramilAdminRole->id, ['assigned_by' => 1]);
                }

                $this->command->info("Koramil Admin user created: {$koramilAdmin->name} at {$koramil->name} (under {$kodim->name})");
            }
        }

        // Create 5 Reporter users from Koramils under the same selected Kodims
        $reporterRole = \App\Models\Role::where('name', 'Reporter')->first();
        $this->command->info('Creating 5 reporter users under the same Kodims...');

        // Get Koramils under the selected Kodims (excluding those used for Koramil Admins)
        $reporterKoramils = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 4))
            ->whereIn('parent_id', $selectedKodims->pluck('id'))
            ->whereNotIn('id', $adminKoramilIds)
            ->whereNotNull('coverage_district')
            ->orderBy('name')
            ->limit(5)
            ->get();

        // Fallback: if not enough Koramils with coverage, get any Koramils
        if ($reporterKoramils->count() < 5) {
            $reporterKoramils = \App\Models\Office::whereHas('level', fn ($q) => $q->where('level', 4))
                ->whereIn('parent_id', $selectedKodims->pluck('id'))
                ->whereNotIn('id', $adminKoramilIds)
                ->orderBy('name')
                ->limit(5)
                ->get();
        }

        $reporters = [];
        foreach ($reporterKoramils as $index => $koramil) {
            $reporterNumber = $index + 1;

            $reporter = User::updateOrCreate(
                ['phone' => '08123456789'.($reporterNumber)],
                [
                    'name' => "Babinsa {$reporterNumber}",
                    'email' => "babinsa{$reporterNumber}@korem074.com",
                    'nrp' => '3124000'.($reporterNumber),
                    'password' => bcrypt('password'),
                    'office_id' => $koramil->id,
                    'is_approved' => true,
                    'approved_at' => now(),
                    'approved_by' => 1,
                    'is_admin' => false,
                ]
            );

            if ($reporterRole && ! $reporter->hasRole($reporterRole)) {
                $reporter->roles()->attach($reporterRole->id, ['assigned_by' => 1]);
            }

            $reporters[] = $reporter;

            // Get parent Kodim for display
            $parentKodim = \App\Models\Office::find($koramil->parent_id);
            $this->command->info("Reporter user created: {$reporter->name} at {$koramil->name} (under {$parentKodim->name})");
        }

        // Now seed projects and progress (which depend on reporter user)
        $this->call([
            ProjectSeeder::class,
            ProgressSeeder::class,
            // ProgressPhotoSeeder::class,
        ]);
    }
}
