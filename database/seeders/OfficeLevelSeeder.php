<?php

namespace Database\Seeders;

use App\Models\OfficeLevel;
use Illuminate\Database\Seeder;

class OfficeLevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating office levels...');

        $levels = [
            [
                'level' => 1,
                'name' => 'Kodam',
                'description' => 'Komando Daerah Militer (Area Military Command)',
                'is_default_user_level' => false,
            ],
            [
                'level' => 2,
                'name' => 'Korem',
                'description' => 'Komando Resort Militer (Regional Military Command)',
                'is_default_user_level' => false,
            ],
            [
                'level' => 3,
                'name' => 'Kodim',
                'description' => 'Komando Distrik Militer (District Military Command)',
                'is_default_user_level' => false,
            ],
            [
                'level' => 4,
                'name' => 'Koramil',
                'description' => 'Komando Rayon Militer (Sub-district Military Command)',
                'is_default_user_level' => true,
            ],
        ];

        foreach ($levels as $levelData) {
            OfficeLevel::updateOrCreate(
                ['level' => $levelData['level']],
                $levelData
            );
            $this->command->info("Created/Updated level {$levelData['level']}: {$levelData['name']}");
        }

        $this->command->info('Office levels created successfully!');
    }
}
