<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key' => 'app.name',
                'value' => json_encode('LAPJU'),
                'description' => 'Application name',
            ],
            [
                'key' => 'app.description',
                'value' => json_encode('Project Progress Tracking System'),
                'description' => 'Application description',
            ],
            [
                'key' => 'project.default_status',
                'value' => json_encode('pending'),
                'description' => 'Default status for new projects',
            ],
            [
                'key' => 'reports.max_items_per_page',
                'value' => json_encode(50),
                'description' => 'Maximum items to display per page in reports',
            ],
            [
                'key' => 'notifications.enabled',
                'value' => json_encode(true),
                'description' => 'Enable/disable system notifications',
            ],
        ];

        foreach ($settings as $settingData) {
            Setting::updateOrCreate(
                ['key' => $settingData['key']],
                $settingData
            );
        }
    }
}
