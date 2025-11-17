<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Central Java (Jawa Tengah) locations only
        $locations = [
            // Semarang
            [
                'name' => 'Kodam IV/Diponegoro',
                'city_name' => 'Semarang',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Markas Komando Daerah Militer IV/Diponegoro',
            ],
            [
                'name' => 'Rindam IV/Diponegoro',
                'city_name' => 'Semarang',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Resimen Induk Kodam IV/Diponegoro',
            ],
            [
                'name' => 'Lapangan Tembak Srondol',
                'city_name' => 'Semarang',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Fasilitas latihan menembak',
            ],

            // Magelang
            [
                'name' => 'Akmil Magelang',
                'city_name' => 'Magelang',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Akademi Militer',
            ],
            [
                'name' => 'Resimen Mahasiswa Magelang',
                'city_name' => 'Magelang',
                'province_name' => 'Jawa Tengah',
                'notes' => null,
            ],

            // Surakarta
            [
                'name' => 'Korem 074/Warastratama',
                'city_name' => 'Surakarta',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Komando Resort Militer Solo',
            ],
            [
                'name' => 'Kodim 0735/Surakarta',
                'city_name' => 'Surakarta',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Komando Distrik Militer',
            ],

            // Purwokerto
            [
                'name' => 'Korem 071/Wijayakusuma',
                'city_name' => 'Purwokerto',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Komando Resort Militer Banyumas',
            ],

            // Salatiga
            [
                'name' => 'Yonif 411/Pandawa',
                'city_name' => 'Salatiga',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Batalyon Infanteri 411/Pandawa',
            ],

            // Cilacap
            [
                'name' => 'Kodim 0703/Cilacap',
                'city_name' => 'Cilacap',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Komando Distrik Militer Cilacap',
            ],
        ];

        foreach ($locations as $location) {
            Location::create($location);
        }
    }
}
