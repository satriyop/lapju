<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPriceCalculationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();
    }

    public function test_total_price_is_calculated_on_creation(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Test Task',
            'volume' => 10.00,
            'unit' => 'm3',
            'weight' => 50.00,
            'price' => 25.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals(250.00, $task->total_price);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'price' => 25.00,
            'total_price' => 250.00,
        ]);
    }

    public function test_total_price_is_recalculated_on_price_update(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Test Task',
            'volume' => 5.00,
            'unit' => 'm2',
            'weight' => 20.00,
            'price' => 10.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals(50.00, $task->total_price);

        $task->update(['price' => 20.00]);

        $this->assertEquals(100.00, $task->fresh()->total_price);
    }

    public function test_total_price_is_recalculated_on_volume_update(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Test Task',
            'volume' => 3.00,
            'unit' => 'kg',
            'weight' => 15.00,
            'price' => 30.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals(90.00, $task->total_price);

        $task->update(['volume' => 6.00]);

        $this->assertEquals(180.00, $task->fresh()->total_price);
    }

    public function test_total_price_handles_decimal_precision(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Test Task',
            'volume' => 12.50,
            'unit' => 'm3',
            'weight' => 25.75,
            'price' => 15.99,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        // 12.50 * 15.99 = 199.875, should round to 199.88
        $this->assertEquals(199.88, $task->total_price);
    }

    public function test_total_price_with_zero_price(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Test Task',
            'volume' => 100.00,
            'unit' => 'pcs',
            'weight' => 50.00,
            'price' => 0.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals(0.00, $task->total_price);
    }

    public function test_total_price_with_zero_volume(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Test Task',
            'volume' => 0.00,
            'unit' => 'unit',
            'weight' => 10.00,
            'price' => 100.00,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->assertEquals(0.00, $task->total_price);
    }

    public function test_total_price_with_large_numbers(): void
    {
        $task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Test Task',
            'volume' => 99999.99,
            'unit' => 'm3',
            'weight' => 999.99,
            'price' => 9999.99,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        // 99999.99 * 9999.99 = 999,989,000.01
        $expectedTotal = 99999.99 * 9999.99;
        $this->assertEquals(round($expectedTotal, 2), $task->total_price);
    }
}
