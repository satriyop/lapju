<?php

namespace Database\Seeders;

use App\Models\Partner;
use Illuminate\Database\Seeder;

class PartnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $companies = [
            [
                'name' => 'PT. AGRINAS PANGAN NUSANTARA (PERSERO)',
                'description' => 'Perusahaan negara yang bergerak di bidang pangan dan agribisnis',
                'address' => 'Jl. Jendral Sudirman No. 123, Jakarta Pusat, DKI Jakarta',
            ],
        ];

        foreach ($companies as $company) {
            Partner::create($company);
        }
    }
}
