<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationCoordinatesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Generates coordinates for all locations based on district centers.
     */
    public function run(): void
    {
        $districtCenters = $this->getDistrictCenters();
        $updated = 0;

        // Get all locations grouped by city and district
        $locations = Location::all();

        foreach ($locations as $index => $location) {
            $key = $location->city_name.'|'.$location->district_name;

            if (isset($districtCenters[$key])) {
                $center = $districtCenters[$key];

                // Add small random offset to spread villages within district
                // Offset range: approximately 1-3 km spread
                $latOffset = (mt_rand(-300, 300) / 10000);
                $lngOffset = (mt_rand(-300, 300) / 10000);

                $location->update([
                    'latitude' => round($center['lat'] + $latOffset, 6),
                    'longitude' => round($center['lng'] + $lngOffset, 6),
                ]);
                $updated++;
            }
        }

        $this->command->info("Updated {$updated} location coordinates out of {$locations->count()} total locations.");
    }

    private function getDistrictCenters(): array
    {
        return [
            // BOYOLALI
            'Boyolali|Kec. Ampel' => ['lat' => -7.3850, 'lng' => 110.5500],
            'Boyolali|Kec. Andong' => ['lat' => -7.2900, 'lng' => 110.7100],
            'Boyolali|Kec. Banyudono' => ['lat' => -7.4800, 'lng' => 110.6800],
            'Boyolali|Kec. Boyolali' => ['lat' => -7.5300, 'lng' => 110.6000],
            'Boyolali|Kec. Cepogo' => ['lat' => -7.4500, 'lng' => 110.5200],
            'Boyolali|Kec. Gladagsari' => ['lat' => -7.4000, 'lng' => 110.5000],
            'Boyolali|Kec. Juwangi' => ['lat' => -7.2100, 'lng' => 110.7200],
            'Boyolali|Kec. Karanggede' => ['lat' => -7.3500, 'lng' => 110.7200],
            'Boyolali|Kec. Kemusu' => ['lat' => -7.2500, 'lng' => 110.7500],
            'Boyolali|Kec. Klego' => ['lat' => -7.2800, 'lng' => 110.6800],
            'Boyolali|Kec. Mojosongo' => ['lat' => -7.5100, 'lng' => 110.6200],
            'Boyolali|Kec. Musuk' => ['lat' => -7.4700, 'lng' => 110.5500],
            'Boyolali|Kec. Ngemplak' => ['lat' => -7.5500, 'lng' => 110.7200],
            'Boyolali|Kec. Nogosari' => ['lat' => -7.4300, 'lng' => 110.7000],
            'Boyolali|Kec. Sambi' => ['lat' => -7.4600, 'lng' => 110.7300],
            'Boyolali|Kec. Sawit' => ['lat' => -7.5200, 'lng' => 110.7000],
            'Boyolali|Kec. Selo' => ['lat' => -7.4800, 'lng' => 110.4500],
            'Boyolali|Kec. Simo' => ['lat' => -7.4000, 'lng' => 110.6200],
            'Boyolali|Kec. Tamansari' => ['lat' => -7.4400, 'lng' => 110.5200],
            'Boyolali|Kec. Teras' => ['lat' => -7.5000, 'lng' => 110.6500],
            'Boyolali|Kec. Wonosamodro' => ['lat' => -7.2400, 'lng' => 110.6700],
            'Boyolali|Kec. Wonosegoro' => ['lat' => -7.2600, 'lng' => 110.6600],

            // KARANGANYAR
            'Karangayar|Kec. Colomadu' => ['lat' => -7.5500, 'lng' => 110.7800],
            'Karangayar|Kec. Gondangrejo' => ['lat' => -7.5000, 'lng' => 110.8500],
            'Karangayar|Kec. Jaten' => ['lat' => -7.5500, 'lng' => 110.8700],
            'Karangayar|Kec. Jatipuro' => ['lat' => -7.7000, 'lng' => 111.0000],
            'Karangayar|Kec. Jatiyoso' => ['lat' => -7.7200, 'lng' => 111.0500],
            'Karangayar|Kec. Jenawi' => ['lat' => -7.6000, 'lng' => 111.1000],
            'Karangayar|Kec. Jumantono' => ['lat' => -7.6800, 'lng' => 110.9800],
            'Karangayar|Kec. Jumapolo' => ['lat' => -7.6600, 'lng' => 110.9500],
            'Karangayar|Kec. Karanganyar' => ['lat' => -7.6000, 'lng' => 110.9500],
            'Karangayar|Kec. Karangpandan' => ['lat' => -7.6200, 'lng' => 111.0000],
            'Karangayar|Kec. Kebakkramat' => ['lat' => -7.5300, 'lng' => 110.8800],
            'Karangayar|Kec. Kerjo' => ['lat' => -7.5800, 'lng' => 111.0200],
            'Karangayar|Kec. Matesih' => ['lat' => -7.6500, 'lng' => 111.0200],
            'Karangayar|Kec. Mojogedang' => ['lat' => -7.5700, 'lng' => 110.9800],
            'Karangayar|Kec. Ngargoyoso' => ['lat' => -7.6200, 'lng' => 111.0800],
            'Karangayar|Kec. Tasikmadu' => ['lat' => -7.5800, 'lng' => 110.9200],
            'Karangayar|Kec. Tawangmangu' => ['lat' => -7.6500, 'lng' => 111.1200],

            // KLATEN
            'Klaten|Kec. Bayat' => ['lat' => -7.7800, 'lng' => 110.5800],
            'Klaten|Kec. Cawas' => ['lat' => -7.7500, 'lng' => 110.6000],
            'Klaten|Kec. Ceper' => ['lat' => -7.6800, 'lng' => 110.6800],
            'Klaten|Kec. Delanggu' => ['lat' => -7.6200, 'lng' => 110.7200],
            'Klaten|Kec. Gantiwarno' => ['lat' => -7.7200, 'lng' => 110.6500],
            'Klaten|Kec. Jatinom' => ['lat' => -7.5800, 'lng' => 110.5800],
            'Klaten|Kec. Jogonalan' => ['lat' => -7.7000, 'lng' => 110.6200],
            'Klaten|Kec. Juwiring' => ['lat' => -7.6500, 'lng' => 110.7500],
            'Klaten|Kec. Kalikotes' => ['lat' => -7.7100, 'lng' => 110.5900],
            'Klaten|Kec. Karanganaom' => ['lat' => -7.6300, 'lng' => 110.6500],
            'Klaten|Kec. Karangdowo' => ['lat' => -7.7600, 'lng' => 110.6800],
            'Klaten|Kec. Karangnongko' => ['lat' => -7.6000, 'lng' => 110.5600],
            'Klaten|Kec. Kebonarum' => ['lat' => -7.6900, 'lng' => 110.5700],
            'Klaten|Kec. Kemalang' => ['lat' => -7.5500, 'lng' => 110.4800],
            'Klaten|Kec. Klaten Selatan' => ['lat' => -7.7100, 'lng' => 110.6000],
            'Klaten|Kec. Klaten Tengah' => ['lat' => -7.7050, 'lng' => 110.6050],
            'Klaten|Kec. Klaten Utara' => ['lat' => -7.6900, 'lng' => 110.6100],
            'Klaten|Kec. Manisrenggo' => ['lat' => -7.6100, 'lng' => 110.5200],
            'Klaten|Kec. Ngawen' => ['lat' => -7.6400, 'lng' => 110.6100],
            'Klaten|Kec. Pedan' => ['lat' => -7.6700, 'lng' => 110.7200],
            'Klaten|Kec. Polanharjo' => ['lat' => -7.6000, 'lng' => 110.6800],
            'Klaten|Kec. Prambanan' => ['lat' => -7.7500, 'lng' => 110.5000],
            'Klaten|Kec. Trucuk' => ['lat' => -7.7300, 'lng' => 110.6600],
            'Klaten|Kec. Tulung' => ['lat' => -7.5900, 'lng' => 110.6200],
            'Klaten|Kec. Wedi' => ['lat' => -7.7400, 'lng' => 110.6200],
            'Klaten|Kec. Wonosari' => ['lat' => -7.6600, 'lng' => 110.6500],

            // SRAGEN
            'Sragen|Kec. Gemolong' => ['lat' => -7.4300, 'lng' => 110.8500],
            'Sragen|Kec. Gesi' => ['lat' => -7.3500, 'lng' => 110.9500],
            'Sragen|Kec. Gondang' => ['lat' => -7.4800, 'lng' => 110.9200],
            'Sragen|Kec. Jenar' => ['lat' => -7.3200, 'lng' => 110.9200],
            'Sragen|Kec. Kaliambe' => ['lat' => -7.5500, 'lng' => 111.0500],
            'Sragen|Kec. Karangmalang' => ['lat' => -7.4500, 'lng' => 110.9800],
            'Sragen|Kec. Kedawung' => ['lat' => -7.4000, 'lng' => 110.8800],
            'Sragen|Kec. Masaran' => ['lat' => -7.5200, 'lng' => 110.9000],
            'Sragen|Kec. Miri' => ['lat' => -7.4100, 'lng' => 110.9500],
            'Sragen|Kec. Mondokan' => ['lat' => -7.3300, 'lng' => 110.9800],
            'Sragen|Kec. Ngampal' => ['lat' => -7.4600, 'lng' => 110.9000],
            'Sragen|Kec. Plupuh' => ['lat' => -7.5000, 'lng' => 110.8500],
            'Sragen|Kec. Sambirejo' => ['lat' => -7.3800, 'lng' => 110.9800],
            'Sragen|Kec. Sambungmacan' => ['lat' => -7.4400, 'lng' => 110.9500],
            'Sragen|Kec. Sidoharjo' => ['lat' => -7.4700, 'lng' => 110.8800],
            'Sragen|Kec. Sragen' => ['lat' => -7.4300, 'lng' => 110.9400],
            'Sragen|Kec. Sukodono' => ['lat' => -7.3600, 'lng' => 110.9000],
            'Sragen|Kec. Sumberlawang' => ['lat' => -7.3400, 'lng' => 110.8600],
            'Sragen|Kec. Tangen' => ['lat' => -7.3000, 'lng' => 110.9500],
            'Sragen|Kec. Tanon' => ['lat' => -7.3700, 'lng' => 110.8900],

            // SUKOHARJO
            'Sukoharjo|Kec. Baki' => ['lat' => -7.5800, 'lng' => 110.7800],
            'Sukoharjo|Kec. Bendosari' => ['lat' => -7.6500, 'lng' => 110.8200],
            'Sukoharjo|Kec. Bulu' => ['lat' => -7.7000, 'lng' => 110.8500],
            'Sukoharjo|Kec. Gatak' => ['lat' => -7.5600, 'lng' => 110.7500],
            'Sukoharjo|Kec. Grogol' => ['lat' => -7.5900, 'lng' => 110.8000],
            'Sukoharjo|Kec. Kartasura' => ['lat' => -7.5500, 'lng' => 110.7400],
            'Sukoharjo|Kec. Mojolaban' => ['lat' => -7.6000, 'lng' => 110.8500],
            'Sukoharjo|Kec. Nguter' => ['lat' => -7.7200, 'lng' => 110.8800],
            'Sukoharjo|Kec. Polokarto' => ['lat' => -7.6300, 'lng' => 110.8800],
            'Sukoharjo|Kec. Sukoharjo' => ['lat' => -7.6800, 'lng' => 110.8400],
            'Sukoharjo|Kec. Tawangsari' => ['lat' => -7.7400, 'lng' => 110.8600],
            'Sukoharjo|Kec. Weru' => ['lat' => -7.7100, 'lng' => 110.8000],

            // SURAKARTA (SOLO)
            'Surakarta|Kec. Banjarsari' => ['lat' => -7.5500, 'lng' => 110.8100],
            'Surakarta|Kec. Jebres' => ['lat' => -7.5600, 'lng' => 110.8400],
            'Surakarta|Kec. Laweyan' => ['lat' => -7.5700, 'lng' => 110.7900],
            'Surakarta|Kec. Pasarkliwon' => ['lat' => -7.5800, 'lng' => 110.8300],
            'Surakarta|Kec. Serengan' => ['lat' => -7.5750, 'lng' => 110.8150],

            // WONOGIRI
            'Wonogiri|Kec. Baturetno' => ['lat' => -7.9000, 'lng' => 110.9500],
            'Wonogiri|Kec. Batuwarno' => ['lat' => -7.9500, 'lng' => 110.9000],
            'Wonogiri|Kec. Bulukerto' => ['lat' => -7.8200, 'lng' => 110.8500],
            'Wonogiri|Kec. Eromoko' => ['lat' => -7.9800, 'lng' => 110.8500],
            'Wonogiri|Kec. Girimarto' => ['lat' => -7.7800, 'lng' => 110.9500],
            'Wonogiri|Kec. Giritontro' => ['lat' => -8.0500, 'lng' => 110.8000],
            'Wonogiri|Kec. Giriwoyo' => ['lat' => -8.0000, 'lng' => 110.8800],
            'Wonogiri|Kec. Jatipurno' => ['lat' => -7.8500, 'lng' => 110.9200],
            'Wonogiri|Kec. Jatiroto' => ['lat' => -7.8800, 'lng' => 110.8800],
            'Wonogiri|Kec. Jatisrono' => ['lat' => -7.8300, 'lng' => 110.9000],
            'Wonogiri|Kec. Kismantoro' => ['lat' => -7.9200, 'lng' => 111.0000],
            'Wonogiri|Kec. Manyaran' => ['lat' => -7.7500, 'lng' => 110.8200],
            'Wonogiri|Kec. Ngadirojo' => ['lat' => -7.8600, 'lng' => 110.9800],
            'Wonogiri|Kec. Nguntoronadi' => ['lat' => -7.9300, 'lng' => 110.9300],
            'Wonogiri|Kec. Paranggupito' => ['lat' => -8.1000, 'lng' => 110.8500],
            'Wonogiri|Kec. Pracimantoro' => ['lat' => -8.0300, 'lng' => 110.8200],
            'Wonogiri|Kec. Puhpelem' => ['lat' => -7.8000, 'lng' => 111.0200],
            'Wonogiri|Kec. Purwantoro' => ['lat' => -7.8400, 'lng' => 111.0000],
            'Wonogiri|Kec. Selogiri' => ['lat' => -7.8100, 'lng' => 110.8200],
            'Wonogiri|Kec. Sidoharjo' => ['lat' => -7.8700, 'lng' => 110.8500],
            'Wonogiri|Kec. Slogohimo' => ['lat' => -7.8900, 'lng' => 111.0200],
            'Wonogiri|Kec. Tirtomoyo' => ['lat' => -7.9600, 'lng' => 110.9800],
            'Wonogiri|Kec. Wonogiri' => ['lat' => -7.8200, 'lng' => 110.9100],
            'Wonogiri|Kec. Wuryantoro' => ['lat' => -7.8600, 'lng' => 110.8300],
        ];
    }
}
