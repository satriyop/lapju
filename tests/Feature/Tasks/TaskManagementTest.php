<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_approved' => true]);
        $this->project = Project::factory()->create();
    }

    public function test_task_can_be_created_with_required_fields(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Foundation Work',
            'volume' => 100.50,
            'unit' => 'm³',
            'weight' => 25.00,
            'price' => 150000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'name' => 'Foundation Work',
            'volume' => 100.50,
            'unit' => 'm³',
        ]);
    }

    public function test_task_automatically_calculates_total_price(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Concrete Work',
            'volume' => 50.00,
            'unit' => 'm³',
            'weight' => 10.00,
            'price' => 200000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        // total_price should be volume * price
        $expectedTotal = 50.00 * 200000.00;
        $this->assertEquals($expectedTotal, $task->total_price);
    }

    public function test_nested_set_structure_maintained_for_parent_child(): void
    {
        $parent = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Parent Task',
            'volume' => 100.00,
            'unit' => 'm³',
            'weight' => 20.00,
            'price' => 100000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $child = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Child Task',
            'volume' => 50.00,
            'unit' => 'm³',
            'weight' => 10.00,
            'price' => 50000.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        // Verify nested set structure
        $this->assertTrue($child->_lft > $parent->_lft);
        $this->assertTrue($child->_rgt < $parent->_rgt);
        $this->assertEquals($parent->id, $child->parent_id);
    }

    public function test_task_belongs_to_project(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $this->assertInstanceOf(Project::class, $task->project);
        $this->assertEquals($this->project->id, $task->project->id);
    }

    public function test_task_can_have_multiple_children(): void
    {
        $parent = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Parent Task',
            'volume' => 100.00,
            'unit' => 'm³',
            'weight' => 20.00,
            'price' => 100000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 6,
        ]);

        $child1 = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Child 1',
            'volume' => 30.00,
            'unit' => 'm³',
            'weight' => 5.00,
            'price' => 30000.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $child2 = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Child 2',
            'volume' => 40.00,
            'unit' => 'm³',
            'weight' => 8.00,
            'price' => 40000.00,
            'parent_id' => $parent->id,
            '_lft' => 4,
            '_rgt' => 5,
        ]);

        $this->assertCount(2, $parent->children);
        $this->assertTrue($parent->children->contains($child1));
        $this->assertTrue($parent->children->contains($child2));
    }

    public function test_task_has_many_progress_entries(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);

        TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'progress_date' => now(),
            'percentage' => 50.00,
        ]);

        TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'progress_date' => now()->subDay(),
            'percentage' => 25.00,
        ]);

        // Verify that task progress relationship exists
        $this->assertTrue($task->progress()->exists());
        $this->assertGreaterThanOrEqual(2, $task->progress()->count());
    }

    public function test_leaf_task_can_have_progress(): void
    {
        $leafTask = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Leaf Task',
            'volume' => 20.00,
            'unit' => 'm²',
            'weight' => 5.00,
            'price' => 50000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $progress = TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $leafTask->id,
            'user_id' => $this->user->id,
            'progress_date' => now(),
            'percentage' => 75.00,
        ]);

        $this->assertTrue($leafTask->progress()->exists());
        $retrievedProgress = TaskProgress::where('task_id', $leafTask->id)
            ->orderBy('progress_date', 'desc')
            ->first();
        $this->assertEquals(75.00, $retrievedProgress->percentage);
    }

    public function test_has_any_descendant_progress_returns_true_when_child_has_progress(): void
    {
        $parent = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Parent',
            'volume' => 100.00,
            'unit' => 'm³',
            'weight' => 20.00,
            'price' => 100000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $child = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Child',
            'volume' => 50.00,
            'unit' => 'm³',
            'weight' => 10.00,
            'price' => 50000.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $child->id,
            'user_id' => $this->user->id,
            'progress_date' => now(),
            'percentage' => 60.00,
        ]);

        $this->assertTrue($parent->hasAnyDescendantProgress());
    }

    public function test_has_any_descendant_progress_returns_false_when_no_progress(): void
    {
        $parent = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Parent',
            'volume' => 100.00,
            'unit' => 'm³',
            'weight' => 20.00,
            'price' => 100000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 4,
        ]);

        $child = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Child',
            'volume' => 50.00,
            'unit' => 'm³',
            'weight' => 10.00,
            'price' => 50000.00,
            'parent_id' => $parent->id,
            '_lft' => 2,
            '_rgt' => 3,
        ]);

        $this->assertFalse($parent->hasAnyDescendantProgress());
    }

    public function test_task_volume_and_weight_stored_as_decimal(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Precise Task',
            'volume' => 123.45,
            'unit' => 'm³',
            'weight' => 67.89,
            'price' => 100000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals(123.45, $task->volume);
        $this->assertEquals(67.89, $task->weight);
    }

    public function test_deleting_project_cascades_to_tasks(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $taskId = $task->id;

        $this->project->delete();

        $this->assertDatabaseMissing('tasks', [
            'id' => $taskId,
        ]);
    }

    public function test_task_can_reference_template_task(): void
    {
        // Create a simple template task directly without factory
        $templateTask = \App\Models\TaskTemplate::create([
            'name' => 'Template Task',
            'unit' => 'm³',
            'weight' => 10.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $task = Task::create([
            'project_id' => $this->project->id,
            'template_task_id' => $templateTask->id,
            'name' => 'From Template',
            'volume' => 50.00,
            'unit' => 'm²',
            'weight' => 10.00,
            'price' => 75000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertInstanceOf(\App\Models\TaskTemplate::class, $task->template);
        $this->assertEquals($templateTask->id, $task->template->id);
    }

    public function test_nested_set_boundaries_properly_ordered(): void
    {
        $root = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Root',
            'volume' => 100.00,
            'unit' => 'm³',
            'weight' => 20.00,
            'price' => 100000.00,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 8,
        ]);

        $child1 = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Child 1',
            'volume' => 30.00,
            'unit' => 'm³',
            'weight' => 5.00,
            'price' => 30000.00,
            'parent_id' => $root->id,
            '_lft' => 2,
            '_rgt' => 5,
        ]);

        $grandchild = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Grandchild',
            'volume' => 10.00,
            'unit' => 'm³',
            'weight' => 2.00,
            'price' => 10000.00,
            'parent_id' => $child1->id,
            '_lft' => 3,
            '_rgt' => 4,
        ]);

        $child2 = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Child 2',
            'volume' => 40.00,
            'unit' => 'm³',
            'weight' => 8.00,
            'price' => 40000.00,
            'parent_id' => $root->id,
            '_lft' => 6,
            '_rgt' => 7,
        ]);

        // Verify nested set boundaries
        $this->assertTrue($child1->_lft > $root->_lft && $child1->_rgt < $root->_rgt);
        $this->assertTrue($grandchild->_lft > $child1->_lft && $grandchild->_rgt < $child1->_rgt);
        $this->assertTrue($child2->_lft > $root->_lft && $child2->_rgt < $root->_rgt);
        $this->assertTrue($child2->_lft > $child1->_rgt);
    }
}
