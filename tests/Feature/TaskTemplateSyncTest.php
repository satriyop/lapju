<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TaskTemplateSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TaskTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $this->template = TaskTemplate::factory()->create([
            'name' => 'Test Template',
            'volume' => 100.00,
            'price' => 500.00,
            'weight' => 10.00,
            'unit' => 'm²',
        ]);
    }

    public function test_sync_button_shows_for_templates_with_tasks(): void
    {
        $this->actingAs($this->admin);

        // Create a project - ProjectObserver auto-clones template to task
        Project::factory()->create();

        // Template with tasks - sync button should appear (wire:click handler)
        Volt::test('admin.task-templates.index')
            ->assertSee('showSyncConfirmation');
    }

    public function test_sync_confirmation_modal_shows_correct_counts(): void
    {
        $this->actingAs($this->admin);

        // Create 3 projects - the ProjectObserver will auto-clone all templates to each project
        // So each project gets a task from $this->template automatically
        Project::factory()->count(3)->create();

        // Get actual counts for assertion (since ProjectObserver clones all templates)
        $expectedProjectsCount = Project::whereHas('tasks', fn ($q) => $q->where('template_task_id', $this->template->id))->count();
        $expectedTasksCount = Task::where('template_task_id', $this->template->id)->count();

        Volt::test('admin.task-templates.index')
            ->call('showSyncConfirmation', $this->template->id)
            ->assertSet('showSyncModal', true)
            ->assertSet('syncTemplateId', $this->template->id)
            ->assertSet('syncProjectsCount', $expectedProjectsCount)
            ->assertSet('syncTasksCount', $expectedTasksCount);

        // Verify that we have the expected number of projects and tasks
        $this->assertEquals(3, $expectedProjectsCount);
        $this->assertEquals(3, $expectedTasksCount);
    }

    public function test_sync_updates_task_values_from_template(): void
    {
        $this->actingAs($this->admin);

        // Create projects - ProjectObserver auto-clones template to tasks
        $project1 = Project::factory()->create();
        $project2 = Project::factory()->create();

        // Get the auto-cloned tasks from our template
        $task1 = Task::where('project_id', $project1->id)
            ->where('template_task_id', $this->template->id)
            ->first();
        $task2 = Task::where('project_id', $project2->id)
            ->where('template_task_id', $this->template->id)
            ->first();

        // Modify tasks to have different values (simulating diverged data)
        $task1->update([
            'name' => 'Old Name 1',
            'volume' => 50.00,
            'price' => 100.00,
            'weight' => 5.00,
            'unit' => 'm',
        ]);

        $task2->update([
            'name' => 'Old Name 2',
            'volume' => 75.00,
            'price' => 200.00,
            'weight' => 7.50,
            'unit' => 'kg',
        ]);

        // Perform sync
        Volt::test('admin.task-templates.index')
            ->call('showSyncConfirmation', $this->template->id)
            ->call('syncToProjects')
            ->assertSet('showSyncModal', false)
            ->assertDispatched('tasks-synced');

        // Verify tasks were updated to match template
        $task1->refresh();
        $task2->refresh();

        $this->assertEquals($this->template->name, $task1->name);
        $this->assertEquals((float) $this->template->volume, (float) $task1->volume);
        $this->assertEquals((float) $this->template->price, (float) $task1->price);
        $this->assertEquals((float) $this->template->weight, (float) $task1->weight);
        $this->assertEquals($this->template->unit, $task1->unit);

        $this->assertEquals($this->template->name, $task2->name);
        $this->assertEquals((float) $this->template->volume, (float) $task2->volume);
        $this->assertEquals((float) $this->template->price, (float) $task2->price);
        $this->assertEquals((float) $this->template->weight, (float) $task2->weight);
        $this->assertEquals($this->template->unit, $task2->unit);
    }

    public function test_sync_recalculates_total_price(): void
    {
        $this->actingAs($this->admin);

        // Create project - ProjectObserver auto-clones template to task
        $project = Project::factory()->create();

        // Get the auto-cloned task
        $task = Task::where('project_id', $project->id)
            ->where('template_task_id', $this->template->id)
            ->first();

        // Modify task to have different values
        $task->update([
            'volume' => 10.00,
            'price' => 50.00,
        ]);
        $task->refresh();

        // Verify initial total_price (10 * 50 = 500)
        $this->assertEquals(500.00, (float) $task->total_price);

        // Perform sync (template has volume=100, price=500)
        Volt::test('admin.task-templates.index')
            ->call('showSyncConfirmation', $this->template->id)
            ->call('syncToProjects');

        $task->refresh();

        // total_price should be recalculated: 100 * 500 = 50000
        $this->assertEquals(50000.00, (float) $task->total_price);
    }

    public function test_sync_does_not_affect_progress_data(): void
    {
        $this->actingAs($this->admin);

        // Create project - ProjectObserver auto-clones template to task
        $project = Project::factory()->create();

        // Get the auto-cloned task
        $task = Task::where('project_id', $project->id)
            ->where('template_task_id', $this->template->id)
            ->first();

        // Modify task to have different values
        $task->update([
            'volume' => 50.00,
            'price' => 100.00,
        ]);

        // Create progress entries
        $progress1 = TaskProgress::factory()->create([
            'task_id' => $task->id,
            'percentage' => 25.00,
            'notes' => 'First progress note',
        ]);

        $progress2 = TaskProgress::factory()->create([
            'task_id' => $task->id,
            'percentage' => 50.00,
            'notes' => 'Second progress note',
        ]);

        // Perform sync
        Volt::test('admin.task-templates.index')
            ->call('showSyncConfirmation', $this->template->id)
            ->call('syncToProjects');

        // Verify progress data is unchanged
        $progress1->refresh();
        $progress2->refresh();

        $this->assertEquals(25.00, (float) $progress1->percentage);
        $this->assertEquals('First progress note', $progress1->notes);
        $this->assertEquals(50.00, (float) $progress2->percentage);
        $this->assertEquals('Second progress note', $progress2->notes);
    }

    public function test_cancel_sync_closes_modal_without_changes(): void
    {
        $this->actingAs($this->admin);

        // Create project - ProjectObserver auto-clones template to task
        $project = Project::factory()->create();

        // Get the auto-cloned task
        $task = Task::where('project_id', $project->id)
            ->where('template_task_id', $this->template->id)
            ->first();

        // Modify task to have different values
        $task->update([
            'name' => 'Original Name',
            'volume' => 50.00,
        ]);

        Volt::test('admin.task-templates.index')
            ->call('showSyncConfirmation', $this->template->id)
            ->assertSet('showSyncModal', true)
            ->call('cancelSync')
            ->assertSet('showSyncModal', false)
            ->assertSet('syncTemplateId', null);

        // Verify task was not changed
        $task->refresh();
        $this->assertEquals('Original Name', $task->name);
        $this->assertEquals(50.00, (float) $task->volume);
    }
}
