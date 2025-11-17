<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
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
            [
                'name' => 'PT. SEMEN INDONESIA (PERSERO) TBK',
                'description' => 'Perusahaan produsen semen terbesar di Indonesia',
                'address' => 'Jl. Veteran No. 45, Gresik, Jawa Timur',
            ],
            [
                'name' => 'PT. TELKOM INDONESIA (PERSERO) TBK',
                'description' => 'Perusahaan telekomunikasi dan jaringan terbesar di Indonesia',
                'address' => 'Jl. Japati No. 1, Bandung, Jawa Barat',
            ],
            [
                'name' => 'PT. PERTAMINA (PERSERO)',
                'description' => 'Perusahaan minyak dan gas bumi negara',
                'address' => 'Jl. Medan Merdeka Timur No. 1A, Jakarta Pusat, DKI Jakarta',
            ],
            [
                'name' => 'PT. PLN (PERSERO)',
                'description' => 'Perusahaan listrik negara',
                'address' => 'Jl. Trunojoyo Blok M-I/135, Jakarta Selatan, DKI Jakarta',
            ],
            [
                'name' => 'PT. BANK RAKYAT INDONESIA (PERSERO) TBK',
                'description' => 'Bank pemerintah terbesar di Indonesia',
                'address' => 'Jl. Jenderal Sudirman Kav. 44-46, Jakarta Pusat, DKI Jakarta',
            ],
            [
                'name' => 'PT. KERETA API INDONESIA (PERSERO)',
                'description' => 'Perusahaan kereta api negara',
                'address' => 'Jl. Perintis Kemerdekaan No. 1, Bandung, Jawa Barat',
            ],
            [
                'name' => 'PT. PELABUHAN INDONESIA (PERSERO)',
                'description' => 'Perusahaan pengelola pelabuhan di Indonesia',
                'address' => 'Jl. Pasoso No. 1, Jakarta Utara, DKI Jakarta',
            ],
            [
                'name' => 'PT. WIJAYA KARYA (PERSERO) TBK',
                'description' => 'Perusahaan konstruksi dan infrastruktur BUMN',
                'address' => 'Jl. D.I. Panjaitan Kav. 9, Jakarta Timur, DKI Jakarta',
            ],
            [
                'name' => 'PT. ADHI KARYA (PERSERO) TBK',
                'description' => 'Perusahaan konstruksi dan engineering terkemuka',
                'address' => 'Jl. Raya Pasar Minggu Km. 18, Jakarta Selatan, DKI Jakarta',
            ],
        ];

        foreach ($companies as $company) {
            Customer::create($company);
        }
    }
}
