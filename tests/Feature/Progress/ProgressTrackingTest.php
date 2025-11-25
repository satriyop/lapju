<?php

declare(strict_types=1);

namespace Tests\Feature\Progress;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $user;
    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_approved' => true]);
        $this->project = Project::factory()->create();
        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            '_lft' => 1,
            '_rgt' => 2,
        ]);
    }

    public function test_progress_can_be_created_for_task(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 45.50,
            'progress_date' => now(),
            'notes' => 'Foundation work completed',
        ]);

        $this->assertDatabaseHas('task_progress', [
            'id' => $progress->id,
            'task_id' => $this->task->id,
            'percentage' => 45.50,
        ]);
    }

    public function test_progress_percentage_stored_as_decimal(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 33.33,
            'progress_date' => now(),
        ]);

        $this->assertEquals(33.33, $progress->percentage);
    }

    public function test_progress_belongs_to_task(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $this->assertInstanceOf(Task::class, $progress->task);
        $this->assertEquals($this->task->id, $progress->task->id);
    }

    public function test_progress_belongs_to_project(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $this->assertInstanceOf(Project::class, $progress->project);
        $this->assertEquals($this->project->id, $progress->project->id);
    }

    public function test_progress_belongs_to_user(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $this->assertInstanceOf(User::class, $progress->user);
        $this->assertEquals($this->user->id, $progress->user->id);
    }

    public function test_progress_date_is_cast_to_date(): void
    {
        $date = now()->startOfDay();

        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => $date,
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $progress->progress_date);
        $this->assertEquals($date->toDateString(), $progress->progress_date->toDateString());
    }

    public function test_multiple_progress_entries_for_same_task(): void
    {
        TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 25.00,
            'progress_date' => now()->subDays(2),
        ]);

        TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now()->subDay(),
        ]);

        TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 75.00,
            'progress_date' => now(),
        ]);

        $count = TaskProgress::where('task_id', $this->task->id)->count();
        $this->assertGreaterThanOrEqual(3, $count);
    }

    public function test_progress_can_be_updated(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $progress->update(['percentage' => 75.00]);

        $this->assertEquals(75.00, $progress->fresh()->percentage);
    }

    public function test_progress_notes_are_optional(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $this->assertNull($progress->notes);
    }

    public function test_progress_notes_can_be_added(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
            'notes' => 'Concrete pouring completed',
        ]);

        $this->assertEquals('Concrete pouring completed', $progress->notes);
    }

    public function test_progress_percentage_can_be_zero(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 0.00,
            'progress_date' => now(),
        ]);

        $this->assertEquals(0.00, $progress->percentage);
    }

    public function test_progress_percentage_can_be_hundred(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 100.00,
            'progress_date' => now(),
        ]);

        $this->assertEquals(100.00, $progress->percentage);
    }

    public function test_deleting_task_cascades_to_progress(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $progressId = $progress->id;
        $this->task->delete();

        $this->assertDatabaseMissing('task_progress', [
            'id' => $progressId,
        ]);
    }

    public function test_deleting_project_cascades_to_progress(): void
    {
        $progress = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $progressId = $progress->id;
        $this->project->delete();

        $this->assertDatabaseMissing('task_progress', [
            'id' => $progressId,
        ]);
    }

    public function test_progress_for_different_users_on_different_dates(): void
    {
        $user2 = User::factory()->create(['is_approved' => true]);
        $date1 = now();
        $date2 = now()->addDay();

        $progress1 = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => $date1,
        ]);

        $progress2 = TaskProgress::create([
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'user_id' => $user2->id,
            'percentage' => 60.00,
            'progress_date' => $date2,
        ]);

        $this->assertEquals(50.00, $progress1->fresh()->percentage);
        $this->assertEquals(60.00, $progress2->fresh()->percentage);
        $this->assertEquals($this->user->id, $progress1->user_id);
        $this->assertEquals($user2->id, $progress2->user_id);
    }
}
