<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\OfficeLevel;
use Illuminate\Database\Seeder;

class OfficeSeeder extends Seeder
{
    /**
     * Seed the offices table with complete Korem 074/Warastratama hierarchy.
     * Includes Kodam IV/Diponegoro -> Korem 074 -> All Kodim -> All Koramil.
     */
    public function run(): void
    {
        $this->command->info('Seeding office levels and offices...');

        // Create office levels
        $this->seedOfficeLevels();

        // Create office hierarchy
        $this->seedOfficeHierarchy();

        $this->command->info('Office seeding completed successfully!');
        $this->showSummary();
    }

    /**
     * Seed office levels (configurable naming for each level).
     */
    private function seedOfficeLevels(): void
    {
        $levels = [
            [
                'level' => 1,
                'name' => 'Kodam',
                'description' => 'Komando Daerah Militer - tingkat provinsi',
                'is_default_user_level' => false,
            ],
            [
                'level' => 2,
                'name' => 'Korem',
                'description' => 'Komando Resort Militer - tingkat beberapa kabupaten/kota',
                'is_default_user_level' => false,
            ],
            [
                'level' => 3,
                'name' => 'Kodim',
                'description' => 'Komando Distrik Militer - tingkat kabupaten/kota',
                'is_default_user_level' => false,
            ],
            [
                'level' => 4,
                'name' => 'Koramil',
                'description' => 'Komando Rayon Militer - tingkat kecamatan',
                'is_default_user_level' => true, // Default user level
            ],
        ];

        foreach ($levels as $level) {
            OfficeLevel::updateOrCreate(
                ['level' => $level['level']],
                $level
            );
        }

        $this->command->info('Created '.count($levels).' office levels.');
    }

    /**
     * Seed the complete office hierarchy for Korem 074/Warastratama.
     */
    private function seedOfficeHierarchy(): void
    {
        // Get level IDs
        $kodamLevel = OfficeLevel::where('level', 1)->first();
        $koremLevel = OfficeLevel::where('level', 2)->first();
        $kodimLevel = OfficeLevel::where('level', 3)->first();
        $koramilLevel = OfficeLevel::where('level', 4)->first();

        // Counter for nested set values
        $counter = 1;

        // Create Kodam IV/Diponegoro
        $kodam = Office::create([
            'parent_id' => null,
            'level_id' => $kodamLevel->id,
            'name' => 'Kodam IV/Diponegoro',
            'code' => 'IV',
            'notes' => 'Wilayah Jawa Tengah dan DIY',
            '_lft' => $counter++,
            '_rgt' => 0, // Will be updated after all children
        ]);

        // Create Korem 074/Warastratama
        $korem = Office::create([
            'parent_id' => $kodam->id,
            'level_id' => $koremLevel->id,
            'name' => 'Korem 074/Warastratama',
            'code' => '074',
            'notes' => 'Wilayah Karesidenan Surakarta (Solo Raya)',
            '_lft' => $counter++,
            '_rgt' => 0, // Will be updated after all children
        ]);

        // Define all Kodim with their Koramil
        $kodimData = $this->getKodimData();

        foreach ($kodimData as $kodim) {
            $kodimOffice = Office::create([
                'parent_id' => $korem->id,
                'level_id' => $kodimLevel->id,
                'name' => $kodim['name'],
                'code' => $kodim['code'],
                'notes' => $kodim['notes'],
                '_lft' => $counter++,
                '_rgt' => 0, // Will be updated after all Koramil
            ]);

            // Create all Koramil for this Kodim
            foreach ($kodim['koramil'] as $koramil) {
                Office::create([
                    'parent_id' => $kodimOffice->id,
                    'level_id' => $koramilLevel->id,
                    'name' => $koramil['name'],
                    'code' => $koramil['code'],
                    'notes' => $koramil['notes'] ?? null,
                    '_lft' => $counter++,
                    '_rgt' => $counter++,
                ]);
            }

            // Update Kodim _rgt value
            $kodimOffice->update(['_rgt' => $counter++]);
        }

        // Update Korem _rgt value
        $korem->update(['_rgt' => $counter++]);

        // Update Kodam _rgt value
        $kodam->update(['_rgt' => $counter]);
    }

    /**
     * Get all Kodim data with their Koramil for Korem 074/Warastratama.
     */
    private function getKodimData(): array
    {
        return [
            // Kodim 0723/Klaten - 26 Koramil
            [
                'name' => 'Kodim 0723/Klaten',
                'code' => '0723',
                'notes' => 'Wilayah Kabupaten Klaten',
                'koramil' => [
                    ['name' => 'Koramil 01/Prambanan', 'code' => '0723-01'],
                    ['name' => 'Koramil 02/Gantiwarno', 'code' => '0723-02'],
                    ['name' => 'Koramil 03/Wedi', 'code' => '0723-03'],
                    ['name' => 'Koramil 04/Bayat', 'code' => '0723-04'],
                    ['name' => 'Koramil 05/Cawas', 'code' => '0723-05'],
                    ['name' => 'Koramil 06/Trucuk', 'code' => '0723-06'],
                    ['name' => 'Koramil 07/Kalikotes', 'code' => '0723-07'],
                    ['name' => 'Koramil 08/Kebonarum', 'code' => '0723-08'],
                    ['name' => 'Koramil 09/Jogonalan', 'code' => '0723-09'],
                    ['name' => 'Koramil 10/Manisrenggo', 'code' => '0723-10'],
                    ['name' => 'Koramil 11/Karangnongko', 'code' => '0723-11'],
                    ['name' => 'Koramil 12/Ngawen', 'code' => '0723-12'],
                    ['name' => 'Koramil 13/Ceper', 'code' => '0723-13'],
                    ['name' => 'Koramil 14/Pedan', 'code' => '0723-14'],
                    ['name' => 'Koramil 15/Karangdowo', 'code' => '0723-15'],
                    ['name' => 'Koramil 16/Juwiring', 'code' => '0723-16'],
                    ['name' => 'Koramil 17/Wonosari', 'code' => '0723-17'],
                    ['name' => 'Koramil 18/Delanggu', 'code' => '0723-18'],
                    ['name' => 'Koramil 19/Polanharjo', 'code' => '0723-19'],
                    ['name' => 'Koramil 20/Karanganom', 'code' => '0723-20'],
                    ['name' => 'Koramil 21/Tulung', 'code' => '0723-21'],
                    ['name' => 'Koramil 22/Jatinom', 'code' => '0723-22'],
                    ['name' => 'Koramil 23/Kemalang', 'code' => '0723-23'],
                    ['name' => 'Koramil 24/Klaten Selatan', 'code' => '0723-24'],
                    ['name' => 'Koramil 25/Klaten Tengah', 'code' => '0723-25'],
                    ['name' => 'Koramil 26/Klaten Utara', 'code' => '0723-26'],
                ],
            ],

            // Kodim 0724/Boyolali - 22 Koramil
            [
                'name' => 'Kodim 0724/Boyolali',
                'code' => '0724',
                'notes' => 'Wilayah Kabupaten Boyolali',
                'koramil' => [
                    ['name' => 'Koramil 01/Selo', 'code' => '0724-01'],
                    ['name' => 'Koramil 02/Ampel', 'code' => '0724-02'],
                    ['name' => 'Koramil 03/Cepogo', 'code' => '0724-03'],
                    ['name' => 'Koramil 04/Musuk', 'code' => '0724-04'],
                    ['name' => 'Koramil 05/Boyolali', 'code' => '0724-05'],
                    ['name' => 'Koramil 06/Mojosongo', 'code' => '0724-06'],
                    ['name' => 'Koramil 07/Teras', 'code' => '0724-07'],
                    ['name' => 'Koramil 08/Sawit', 'code' => '0724-08'],
                    ['name' => 'Koramil 09/Banyudono', 'code' => '0724-09'],
                    ['name' => 'Koramil 10/Sambi', 'code' => '0724-10'],
                    ['name' => 'Koramil 11/Ngemplak', 'code' => '0724-11'],
                    ['name' => 'Koramil 12/Nogosari', 'code' => '0724-12'],
                    ['name' => 'Koramil 13/Simo', 'code' => '0724-13'],
                    ['name' => 'Koramil 14/Karanggede', 'code' => '0724-14'],
                    ['name' => 'Koramil 15/Klego', 'code' => '0724-15'],
                    ['name' => 'Koramil 16/Andong', 'code' => '0724-16'],
                    ['name' => 'Koramil 17/Kemusu', 'code' => '0724-17'],
                    ['name' => 'Koramil 18/Wonosegoro', 'code' => '0724-18'],
                    ['name' => 'Koramil 19/Juwangi', 'code' => '0724-19'],
                    ['name' => 'Koramil 20/Gladagsari', 'code' => '0724-20'],
                    ['name' => 'Koramil 21/Tamansari', 'code' => '0724-21'],
                    ['name' => 'Koramil 22/Wonosamodro', 'code' => '0724-22'],
                ],
            ],

            // Kodim 0725/Sragen - 20 Koramil
            [
                'name' => 'Kodim 0725/Sragen',
                'code' => '0725',
                'notes' => 'Wilayah Kabupaten Sragen',
                'koramil' => [
                    ['name' => 'Koramil 01/Kalijambe', 'code' => '0725-01'],
                    ['name' => 'Koramil 02/Plupuh', 'code' => '0725-02'],
                    ['name' => 'Koramil 03/Masaran', 'code' => '0725-03'],
                    ['name' => 'Koramil 04/Kedawung', 'code' => '0725-04'],
                    ['name' => 'Koramil 05/Sambirejo', 'code' => '0725-05'],
                    ['name' => 'Koramil 06/Gondang', 'code' => '0725-06'],
                    ['name' => 'Koramil 07/Sambungmacan', 'code' => '0725-07'],
                    ['name' => 'Koramil 08/Ngrampal', 'code' => '0725-08'],
                    ['name' => 'Koramil 09/Karangmalang', 'code' => '0725-09'],
                    ['name' => 'Koramil 10/Sragen', 'code' => '0725-10'],
                    ['name' => 'Koramil 11/Sidoharjo', 'code' => '0725-11'],
                    ['name' => 'Koramil 12/Tanon', 'code' => '0725-12'],
                    ['name' => 'Koramil 13/Gemolong', 'code' => '0725-13'],
                    ['name' => 'Koramil 14/Miri', 'code' => '0725-14'],
                    ['name' => 'Koramil 15/Sumberlawang', 'code' => '0725-15'],
                    ['name' => 'Koramil 16/Mondokan', 'code' => '0725-16'],
                    ['name' => 'Koramil 17/Sukodono', 'code' => '0725-17'],
                    ['name' => 'Koramil 18/Gesi', 'code' => '0725-18'],
                    ['name' => 'Koramil 19/Tangen', 'code' => '0725-19'],
                    ['name' => 'Koramil 20/Jenar', 'code' => '0725-20'],
                ],
            ],

            // Kodim 0726/Sukoharjo - 12 Koramil
            [
                'name' => 'Kodim 0726/Sukoharjo',
                'code' => '0726',
                'notes' => 'Wilayah Kabupaten Sukoharjo',
                'koramil' => [
                    ['name' => 'Koramil 01/Weru', 'code' => '0726-01'],
                    ['name' => 'Koramil 02/Bulu', 'code' => '0726-02'],
                    ['name' => 'Koramil 03/Tawangsari', 'code' => '0726-03'],
                    ['name' => 'Koramil 04/Sukoharjo', 'code' => '0726-04'],
                    ['name' => 'Koramil 05/Nguter', 'code' => '0726-05'],
                    ['name' => 'Koramil 06/Bendosari', 'code' => '0726-06'],
                    ['name' => 'Koramil 07/Polokarto', 'code' => '0726-07'],
                    ['name' => 'Koramil 08/Mojolaban', 'code' => '0726-08'],
                    ['name' => 'Koramil 09/Grogol', 'code' => '0726-09'],
                    ['name' => 'Koramil 10/Baki', 'code' => '0726-10'],
                    ['name' => 'Koramil 11/Gatak', 'code' => '0726-11'],
                    ['name' => 'Koramil 12/Kartasura', 'code' => '0726-12'],
                ],
            ],

            // Kodim 0727/Karanganyar - 17 Koramil
            [
                'name' => 'Kodim 0727/Karanganyar',
                'code' => '0727',
                'notes' => 'Wilayah Kabupaten Karanganyar',
                'koramil' => [
                    ['name' => 'Koramil 01/Jatipuro', 'code' => '0727-01'],
                    ['name' => 'Koramil 02/Jatiyoso', 'code' => '0727-02'],
                    ['name' => 'Koramil 03/Jumapolo', 'code' => '0727-03'],
                    ['name' => 'Koramil 04/Jumantono', 'code' => '0727-04'],
                    ['name' => 'Koramil 05/Matesih', 'code' => '0727-05'],
                    ['name' => 'Koramil 06/Tawangmangu', 'code' => '0727-06'],
                    ['name' => 'Koramil 07/Ngargoyoso', 'code' => '0727-07'],
                    ['name' => 'Koramil 08/Karangpandan', 'code' => '0727-08'],
                    ['name' => 'Koramil 09/Karanganyar', 'code' => '0727-09'],
                    ['name' => 'Koramil 10/Tasikmadu', 'code' => '0727-10'],
                    ['name' => 'Koramil 11/Jaten', 'code' => '0727-11'],
                    ['name' => 'Koramil 12/Colomadu', 'code' => '0727-12'],
                    ['name' => 'Koramil 13/Gondangrejo', 'code' => '0727-13'],
                    ['name' => 'Koramil 14/Kebakkramat', 'code' => '0727-14'],
                    ['name' => 'Koramil 15/Mojogedang', 'code' => '0727-15'],
                    ['name' => 'Koramil 16/Kerjo', 'code' => '0727-16'],
                    ['name' => 'Koramil 17/Jenawi', 'code' => '0727-17'],
                ],
            ],

            // Kodim 0728/Wonogiri - 25 Koramil
            [
                'name' => 'Kodim 0728/Wonogiri',
                'code' => '0728',
                'notes' => 'Wilayah Kabupaten Wonogiri',
                'koramil' => [
                    ['name' => 'Koramil 01/Pracimantoro', 'code' => '0728-01'],
                    ['name' => 'Koramil 02/Paranggupito', 'code' => '0728-02'],
                    ['name' => 'Koramil 03/Giritontro', 'code' => '0728-03'],
                    ['name' => 'Koramil 04/Giriwoyo', 'code' => '0728-04'],
                    ['name' => 'Koramil 05/Batuwarno', 'code' => '0728-05'],
                    ['name' => 'Koramil 06/Karangtengah', 'code' => '0728-06'],
                    ['name' => 'Koramil 07/Tirtomoyo', 'code' => '0728-07'],
                    ['name' => 'Koramil 08/Nguntoronadi', 'code' => '0728-08'],
                    ['name' => 'Koramil 09/Baturetno', 'code' => '0728-09'],
                    ['name' => 'Koramil 10/Eromoko', 'code' => '0728-10'],
                    ['name' => 'Koramil 11/Wuryantoro', 'code' => '0728-11'],
                    ['name' => 'Koramil 12/Manyaran', 'code' => '0728-12'],
                    ['name' => 'Koramil 13/Selogiri', 'code' => '0728-13'],
                    ['name' => 'Koramil 14/Wonogiri', 'code' => '0728-14'],
                    ['name' => 'Koramil 15/Ngadirojo', 'code' => '0728-15'],
                    ['name' => 'Koramil 16/Sidoharjo', 'code' => '0728-16'],
                    ['name' => 'Koramil 17/Jatiroto', 'code' => '0728-17'],
                    ['name' => 'Koramil 18/Kismantoro', 'code' => '0728-18'],
                    ['name' => 'Koramil 19/Purwantoro', 'code' => '0728-19'],
                    ['name' => 'Koramil 20/Bulukerto', 'code' => '0728-20'],
                    ['name' => 'Koramil 21/Slogohimo', 'code' => '0728-21'],
                    ['name' => 'Koramil 22/Jatisrono', 'code' => '0728-22'],
                    ['name' => 'Koramil 23/Jatipurno', 'code' => '0728-23'],
                    ['name' => 'Koramil 24/Girimarto', 'code' => '0728-24'],
                    ['name' => 'Koramil 25/Puhpelem', 'code' => '0728-25'],
                ],
            ],

            // Kodim 0735/Surakarta - 5 Koramil
            [
                'name' => 'Kodim 0735/Surakarta',
                'code' => '0735',
                'notes' => 'Wilayah Kota Surakarta (Solo)',
                'koramil' => [
                    ['name' => 'Koramil 01/Laweyan', 'code' => '0735-01'],
                    ['name' => 'Koramil 02/Serengan', 'code' => '0735-02'],
                    ['name' => 'Koramil 03/Pasar Kliwon', 'code' => '0735-03'],
                    ['name' => 'Koramil 04/Jebres', 'code' => '0735-04'],
                    ['name' => 'Koramil 05/Banjarsari', 'code' => '0735-05'],
                ],
            ],
        ];
    }

    /**
     * Show summary of seeded data.
     */
    private function showSummary(): void
    {
        $this->command->newLine();

        $levelCounts = [];
        $levels = OfficeLevel::orderBy('level')->get();

        foreach ($levels as $level) {
            $count = Office::where('level_id', $level->id)->count();
            $levelCounts[$level->name] = $count;
            $this->command->info("  {$level->name}: {$count} offices");
        }

        $total = Office::count();
        $this->command->info("  Total: {$total} offices");
    }
}
