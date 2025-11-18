<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Central Java (Jawa Tengah) locations - specific cities only
        $locations = [
            // Surakarta
            [
                'village_name' => 'Jebres',
                'city_name' => 'Surakarta',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kelurahan di Kecamatan Jebres',
            ],
            [
                'village_name' => 'Mojosongo',
                'city_name' => 'Surakarta',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kelurahan di Kecamatan Jebres',
            ],
            [
                'village_name' => 'Serengan',
                'city_name' => 'Surakarta',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kelurahan di Kecamatan Serengan',
            ],
            [
                'village_name' => 'Laweyan',
                'city_name' => 'Surakarta',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kelurahan di Kecamatan Laweyan',
            ],

            // Sukoharjo
            [
                'village_name' => 'Kartasura',
                'city_name' => 'Sukoharjo',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Kartasura',
            ],
            [
                'village_name' => 'Grogol',
                'city_name' => 'Sukoharjo',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Grogol',
            ],
            [
                'village_name' => 'Baki',
                'city_name' => 'Sukoharjo',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Baki',
            ],
            [
                'village_name' => 'Gatak',
                'city_name' => 'Sukoharjo',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Gatak',
            ],

            // Karanganyar
            [
                'village_name' => 'Karanganyar',
                'city_name' => 'Karanganyar',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Karanganyar',
            ],
            [
                'village_name' => 'Tawangmangu',
                'city_name' => 'Karanganyar',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Tawangmangu',
            ],
            [
                'village_name' => 'Jaten',
                'city_name' => 'Karanganyar',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Jaten',
            ],
            [
                'village_name' => 'Gondangrejo',
                'city_name' => 'Karanganyar',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Gondangrejo',
            ],

            // Sragen
            [
                'village_name' => 'Sragen',
                'city_name' => 'Sragen',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Sragen',
            ],
            [
                'village_name' => 'Gemolong',
                'city_name' => 'Sragen',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Gemolong',
            ],
            [
                'village_name' => 'Karangmalang',
                'city_name' => 'Sragen',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Karangmalang',
            ],
            [
                'village_name' => 'Masaran',
                'city_name' => 'Sragen',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Masaran',
            ],

            // Klaten
            [
                'village_name' => 'Klaten Utara',
                'city_name' => 'Klaten',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Klaten Utara',
            ],
            [
                'village_name' => 'Klaten Selatan',
                'city_name' => 'Klaten',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Klaten Selatan',
            ],
            [
                'village_name' => 'Delanggu',
                'city_name' => 'Klaten',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Delanggu',
            ],
            [
                'village_name' => 'Prambanan',
                'city_name' => 'Klaten',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Prambanan',
            ],

            // Wonogiri
            [
                'village_name' => 'Wonogiri',
                'city_name' => 'Wonogiri',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Wonogiri',
            ],
            [
                'village_name' => 'Pracimantoro',
                'city_name' => 'Wonogiri',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Pracimantoro',
            ],
            [
                'village_name' => 'Baturetno',
                'city_name' => 'Wonogiri',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Baturetno',
            ],
            [
                'village_name' => 'Ngadirojo',
                'city_name' => 'Wonogiri',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Ngadirojo',
            ],

            // Boyolali
            [
                'village_name' => 'Boyolali',
                'city_name' => 'Boyolali',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Boyolali',
            ],
            [
                'village_name' => 'Selo',
                'city_name' => 'Boyolali',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Selo',
            ],
            [
                'village_name' => 'Ampel',
                'city_name' => 'Boyolali',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Ampel',
            ],
            [
                'village_name' => 'Andong',
                'city_name' => 'Boyolali',
                'province_name' => 'Jawa Tengah',
                'notes' => 'Kecamatan Andong',
            ],
        ];

        foreach ($locations as $location) {
            Location::create($location);
        }
    }
}
