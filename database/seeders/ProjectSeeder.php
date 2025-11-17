<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Location;
use App\Models\Project;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create 15 projects using existing customers and locations
        $customers = Customer::all();
        $locations = Location::all();

        // Create projects with pattern "PEMBANGUNAN KOPERASI MERAH PUTIH {CUSTOMER NAME}"
        foreach ($customers as $customer) {
            // Create 1-2 projects per customer
            $projectCount = rand(1, 2);

            for ($i = 0; $i < $projectCount; $i++) {
                $startDate = fake()->dateTimeBetween('-1 year', '+1 month');
                $endDate = fake()->dateTimeBetween($startDate, '+2 years');

                Project::create([
                    'name' => 'PEMBANGUNAN KOPERASI MERAH PUTIH ' . $customer->name,
                    'description' => 'Proyek pembangunan gedung koperasi untuk meningkatkan kesejahteraan masyarakat melalui ekonomi kerakyatan',
                    'customer_id' => $customer->id,
                    'location_id' => $locations->random()->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => fake()->randomElement(['planning', 'active', 'completed', 'on_hold']),
                ]);
            }
        }
    }
}
