<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_project_cascades_to_progress_photos(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create(['is_approved' => true]);

        $photo = ProgressPhoto::factory()->create([
            'project_id' => $project->id,
            'root_task_id' => $task->id,
            'user_id' => $user->id,
        ]);

        $project->delete();

        $this->assertDatabaseMissing('progress_photos', [
            'id' => $photo->id,
        ]);
    }

    public function test_unique_constraint_prevents_duplicate_daily_photos(): void
    {
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create(['is_approved' => true]);
        $today = now()->toDateString();

        ProgressPhoto::create([
            'project_id' => $project->id,
            'root_task_id' => $task->id,
            'user_id' => $user->id,
            'photo_date' => $today,
            'file_path' => 'test1.jpg',
            'file_name' => 'test1.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => $project->id,
            'root_task_id' => $task->id,
            'user_id' => $user->id,
            'photo_date' => $today,
            'file_path' => 'test2.jpg',
            'file_name' => 'test2.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_nested_set_integrity_maintained_for_tasks(): void
    {
        $project = Project::factory()->create();

        $rootTask = Task::factory()->create([
            'project_id' => $project->id,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $childTask = Task::factory()->create([
            'project_id' => $project->id,
            'parent_id' => $rootTask->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        // Verify nested set structure
        $this->assertTrue($childTask->_lft > $rootTask->_lft);
        $this->assertTrue($childTask->_rgt < $rootTask->_rgt);
    }

    public function test_office_hierarchy_parent_child_relationship(): void
    {
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3]);
        $koramilLevel = OfficeLevel::factory()->create(['level' => 4]);

        $kodim = Office::factory()->create([
            'level_id' => $kodimLevel->id,
            'parent_id' => null,
        ]);

        $koramil = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $kodim->id,
        ]);

        $this->assertEquals($kodim->id, $koramil->parent_id);
        $this->assertTrue($koramil->parent()->exists());
    }

    public function test_project_requires_valid_office_reference(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Project::create([
            'name' => 'Test Project',
            'office_id' => 99999,
            'partner_id' => 1,
            'location_id' => 1,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => 'planning',
        ]);
    }

    public function test_user_office_assignment_integrity(): void
    {
        $koramil = Office::factory()->create([
            'level_id' => OfficeLevel::factory()->create(['level' => 4])->id,
        ]);

        $user = User::factory()->create([
            'office_id' => $koramil->id,
            'is_approved' => true,
        ]);

        $this->assertEquals($koramil->id, $user->office_id);
        $this->assertInstanceOf(Office::class, $user->office);
    }
}
