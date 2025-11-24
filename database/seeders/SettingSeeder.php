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
                'value' => 'LAPJU',
                'description' => 'Application name',
            ],
            [
                'key' => 'app.description',
                'value' => 'Project Progress Tracking System',
                'description' => 'Application description',
            ],
            [
                'key' => 'project.default_status',
                'value' => 'pending',
                'description' => 'Default status for new projects',
            ],
            [
                'key' => 'project.default_start_date',
                'value' => '2025-11-01',
                'description' => 'Default start date for new projects (YYYY-MM-DD format)',
            ],
            [
                'key' => 'project.default_end_date',
                'value' => '2026-01-31',
                'description' => 'Default end date for new projects (YYYY-MM-DD format)',
            ],
            [
                'key' => 'reports.max_items_per_page',
                'value' => 50,
                'description' => 'Maximum items to display per page in reports',
            ],
            [
                'key' => 'notifications.enabled',
                'value' => true,
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
