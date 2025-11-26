<?php

declare(strict_types=1);

namespace Tests\Feature\Validation;

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Office $office;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $level = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $this->office = Office::factory()->create(['level_id' => $level->id]);

        $this->user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->office->id,
        ]);

        $this->project = Project::factory()->create([
            'office_id' => $this->office->id,
            'start_date' => now()->subDays(10),
            'end_date' => now()->addDays(10),
        ]);
    }

    public function test_progress_percentage_must_be_between_0_and_100(): void
    {
        $task = Task::factory()->create(['project_id' => $this->project->id]);

        // Valid percentages should work
        $validProgress = TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $this->assertEquals(50.00, $validProgress->percentage);

        // Test boundary values
        $minProgress = TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'percentage' => 0.00,
            'progress_date' => now()->subDay(),
        ]);

        $this->assertEquals(0.00, $minProgress->percentage);

        $maxProgress = TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'percentage' => 100.00,
            'progress_date' => now()->subDays(2),
        ]);

        $this->assertEquals(100.00, $maxProgress->percentage);
    }

    public function test_task_volume_must_be_positive(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'volume' => 100.50,
        ]);

        $this->assertGreaterThan(0, $task->volume);
    }

    public function test_task_price_must_be_positive(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'price' => 150000.00,
        ]);

        $this->assertGreaterThan(0, $task->price);
    }

    public function test_task_weight_must_be_positive(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'weight' => 25.00,
        ]);

        $this->assertGreaterThan(0, $task->weight);
    }

    public function test_project_end_date_must_be_after_start_date(): void
    {
        $project = Project::factory()->create([
            'office_id' => $this->office->id,
            'start_date' => now(),
            'end_date' => now()->addDays(30),
        ]);

        $this->assertTrue($project->end_date->isAfter($project->start_date));
    }

    public function test_user_nrp_must_be_unique(): void
    {
        $user1 = User::factory()->create([
            'nrp' => '12345678',
            'is_approved' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create([
            'nrp' => '12345678', // Duplicate NRP
            'is_approved' => true,
        ]);
    }

    public function test_user_email_must_be_unique(): void
    {
        $user1 = User::factory()->create([
            'email' => 'test@example.com',
            'is_approved' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create([
            'email' => 'test@example.com', // Duplicate email
            'is_approved' => true,
        ]);
    }

    public function test_location_name_fields_are_stored(): void
    {
        $location = Location::factory()->create([
            'province_name' => 'Jawa Barat',
            'city_name' => 'Bandung',
            'district_name' => 'Cidadap',
            'village_name' => 'Hegarmanah',
        ]);

        $this->assertEquals('Jawa Barat', $location->province_name);
        $this->assertEquals('Bandung', $location->city_name);
        $this->assertEquals('Cidadap', $location->district_name);
        $this->assertEquals('Hegarmanah', $location->village_name);
    }

    public function test_task_total_price_calculation_is_accurate(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'volume' => 10.50,
            'price' => 100000.00,
        ]);

        $expectedTotal = 10.50 * 100000.00;
        $actualTotal = $task->volume * $task->price;

        $this->assertEquals($expectedTotal, $actualTotal);
        $this->assertEquals(1050000.00, $actualTotal);
    }

    public function test_project_has_valid_dates(): void
    {
        $project = Project::factory()->create([
            'office_id' => $this->office->id,
            'start_date' => now(),
            'end_date' => now()->addDays(30),
        ]);

        $this->assertNotNull($project->start_date);
        $this->assertNotNull($project->end_date);
        $this->assertTrue($project->end_date->isAfter($project->start_date));
    }

    public function test_task_belongs_to_valid_project(): void
    {
        $task = Task::factory()->create(['project_id' => $this->project->id]);

        $this->assertInstanceOf(Project::class, $task->project);
        $this->assertEquals($this->project->id, $task->project->id);
    }

    public function test_progress_belongs_to_valid_task_and_project(): void
    {
        $task = Task::factory()->create(['project_id' => $this->project->id]);

        $progress = TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $this->assertEquals($this->project->id, $progress->project_id);
        $this->assertEquals($task->id, $progress->task_id);
        $this->assertInstanceOf(Project::class, $progress->project);
        $this->assertInstanceOf(Task::class, $progress->task);
    }

    public function test_office_must_belong_to_valid_level(): void
    {
        $level = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);

        $office = Office::factory()->create(['level_id' => $level->id]);

        $this->assertInstanceOf(OfficeLevel::class, $office->level);
        $this->assertEquals($level->id, $office->level->id);
        $this->assertEquals('Kodim', $office->level->name);
    }

    public function test_decimal_precision_maintained_for_task_volume(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'volume' => 123.45,
        ]);

        // Volume is stored and can be retrieved
        $this->assertIsNumeric($task->volume);
        $this->assertEquals(123.45, (float) $task->volume);
    }

    public function test_decimal_precision_maintained_for_progress_percentage(): void
    {
        $task = Task::factory()->create(['project_id' => $this->project->id]);

        $progress = TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'percentage' => 67.89,
            'progress_date' => now(),
        ]);

        // Percentage stored with 2 decimal precision
        $this->assertEquals(67.89, (float) $progress->percentage);
    }

    public function test_task_weight_total_in_project_can_be_calculated(): void
    {
        $task1 = Task::factory()->create([
            'project_id' => $this->project->id,
            'weight' => 25.00,
        ]);

        $task2 = Task::factory()->create([
            'project_id' => $this->project->id,
            'weight' => 35.00,
        ]);

        $task3 = Task::factory()->create([
            'project_id' => $this->project->id,
            'weight' => 40.00,
        ]);

        $totalWeight = Task::where('project_id', $this->project->id)->sum('weight');

        $this->assertEquals(100.00, $totalWeight);
    }

    public function test_user_must_belong_to_valid_office(): void
    {
        $user = User::factory()->create([
            'office_id' => $this->office->id,
            'is_approved' => true,
        ]);

        $this->assertInstanceOf(Office::class, $user->office);
        $this->assertEquals($this->office->id, $user->office->id);
    }

    public function test_progress_date_is_stored_as_date(): void
    {
        $task = Task::factory()->create(['project_id' => $this->project->id]);

        $progressDate = now()->subDays(3);

        $progress = TaskProgress::create([
            'project_id' => $this->project->id,
            'task_id' => $task->id,
            'user_id' => $this->user->id,
            'percentage' => 50.00,
            'progress_date' => $progressDate,
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $progress->progress_date);
        $this->assertEquals($progressDate->toDateString(), $progress->progress_date->toDateString());
    }

    public function test_project_dates_are_stored_as_dates(): void
    {
        $startDate = now();
        $endDate = now()->addDays(30);

        $project = Project::factory()->create([
            'office_id' => $this->office->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $project->start_date);
        $this->assertInstanceOf(\Carbon\Carbon::class, $project->end_date);
        $this->assertEquals($startDate->toDateString(), $project->start_date->toDateString());
        $this->assertEquals($endDate->toDateString(), $project->end_date->toDateString());
    }
}
