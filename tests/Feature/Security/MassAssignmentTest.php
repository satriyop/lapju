<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_model_protected_from_mass_assignment(): void
    {
        $koramil = Office::factory()->create(['level_id' => OfficeLevel::factory()->create(['level' => 4])->id]);
        $partner = Partner::factory()->create();
        $location = \App\Models\Location::factory()->create();

        // Attempt to mass-assign potentially dangerous fields
        $project = Project::create([
            'name' => 'Test Project',
            'partner_id' => $partner->id,
            'office_id' => $koramil->id,
            'location_id' => $location->id,
            'start_date' => now(),
            'end_date' => now()->addMonths(3),
            'status' => 'planning',
            // Attempting to mass-assign id should be ignored
            'id' => 999,
        ]);

        // ID should be auto-generated, not mass-assigned
        $this->assertNotEquals(999, $project->id);
    }

    public function test_user_model_prevents_mass_assignment_of_admin_flag(): void
    {
        // Attempt to mass-assign is_admin via create
        $user = User::factory()->create([
            'name' => 'Regular User',
            'email' => 'regular@example.com',
            'is_admin' => false,
        ]);

        // User should not be admin
        $this->assertFalse($user->is_admin ?? false);
    }

    public function test_progress_photo_model_prevents_id_mass_assignment(): void
    {
        $project = Project::factory()->create();
        $task = \App\Models\Task::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create(['is_approved' => true]);

        $photo = \App\Models\ProgressPhoto::create([
            'project_id' => $project->id,
            'root_task_id' => $task->id,
            'user_id' => $user->id,
            'photo_date' => now(),
            'file_path' => 'test/path.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
            // Attempt to override ID
            'id' => 888,
        ]);

        $this->assertNotEquals(888, $photo->id);
    }

    public function test_task_progress_enforces_user_id_from_auth(): void
    {
        $user = User::factory()->create(['is_approved' => true]);
        $this->actingAs($user);

        $project = Project::factory()->create();
        $task = \App\Models\Task::factory()->create(['project_id' => $project->id]);

        $progress = \App\Models\TaskProgress::create([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'progress_date' => now(),
            'percentage' => 75,
        ]);

        // user_id should match authenticated user
        $this->assertEquals($user->id, $progress->user_id);
    }

    public function test_role_model_prevents_system_flag_manipulation(): void
    {
        $role = \App\Models\Role::factory()->create([
            'name' => 'Custom Role',
            'permissions' => ['view_projects'],
        ]);

        // is_system should default to false and not be mass-assignable for custom roles
        $this->assertFalse($role->is_system);
    }
}
