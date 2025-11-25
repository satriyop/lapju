<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Admin',
                'description' => 'Full system administrator with all permissions',
                'office_level_id' => null,
                'permissions' => ['*'],
                'is_system' => true,
            ],
            [
                'name' => 'Reporter',
                'description' => 'Can create projects, report progress and update task completion at Koramil level',
                'office_level_id' => 4, // Koramil level
                'permissions' => ['view_projects', 'create_projects', 'update_progress', 'view_tasks'],
                'is_system' => false,
            ],
            [
                'name' => 'Viewer',
                'description' => 'Read-only access to view projects and reports',
                'office_level_id' => null,
                'permissions' => ['view_projects', 'view_tasks', 'view_reports'],
                'is_system' => false,
            ],
            [
                'name' => 'Kodim Admin',
                'description' => 'Can manage projects, tasks, approve users at Kodim level',
                'office_level_id' => 3, // Kodim level
                'permissions' => ['view_projects', 'edit_projects', 'view_tasks', 'edit_tasks', 'view_reports', 'manage_users'],
                'is_system' => false,
            ],
            [
                'name' => 'Koramil Admin',
                'description' => 'Can approve users and manage projects at Koramil level',
                'office_level_id' => 4, // Koramil level
                'permissions' => ['view_projects', 'create_projects', 'edit_projects', 'delete_projects', 'update_progress', 'view_tasks', 'manage_users'],
                'is_system' => false,
            ],
        ];

        foreach ($roles as $roleData) {
            Role::updateOrCreate(
                ['name' => $roleData['name']],
                $roleData
            );
        }
    }
}
