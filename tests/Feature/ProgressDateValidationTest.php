<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProgressDateValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user with full permissions
        $this->user = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $location = Location::factory()->create();
        $partner = Partner::factory()->create();

        $this->project = Project::factory()->create([
            'location_id' => $location->id,
            'partner_id' => $partner->id,
            'start_date' => now()->subDays(30),
            'end_date' => now()->addDays(30),
        ]);

        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);
    }

    public function test_can_save_progress_for_today(): void
    {
        $this->actingAs($this->user);
        $today = now()->format('Y-m-d');

        Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $today)
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => 50.0,
                    'notes' => 'Progress for today',
                ],
            ])
            ->call('saveProgress', $this->task->id)
            ->assertHasNoErrors();

        $progress = TaskProgress::where('task_id', $this->task->id)
            ->where('project_id', $this->project->id)
            ->where('user_id', $this->user->id)
            ->whereDate('progress_date', $today)
            ->first();

        $this->assertNotNull($progress);
        $this->assertEquals($today, Carbon::parse($progress->progress_date)->format('Y-m-d'));
        $this->assertEquals(50.0, $progress->percentage);
        $this->assertEquals('Progress for today', $progress->notes);
    }

    public function test_can_save_progress_for_past_date(): void
    {
        $this->actingAs($this->user);
        $pastDate = now()->subDays(5)->format('Y-m-d');

        Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $pastDate)
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => 75.0,
                    'notes' => 'Back-dated progress entry',
                ],
            ])
            ->call('saveProgress', $this->task->id)
            ->assertHasNoErrors();

        $progress = TaskProgress::where('task_id', $this->task->id)
            ->where('project_id', $this->project->id)
            ->where('user_id', $this->user->id)
            ->whereDate('progress_date', $pastDate)
            ->first();

        $this->assertNotNull($progress);
        $this->assertEquals($pastDate, Carbon::parse($progress->progress_date)->format('Y-m-d'));
        $this->assertEquals(75.0, $progress->percentage);
    }

    public function test_cannot_save_progress_for_future_date(): void
    {
        $this->actingAs($this->user);
        $futureDate = now()->addDays(1)->format('Y-m-d');

        Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $futureDate)
            ->set('maxDate', now()->format('Y-m-d'))
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => 50.0,
                    'notes' => 'Future progress',
                ],
            ])
            ->call('saveProgress', $this->task->id)
            ->assertHasErrors('selectedDate');

        $this->assertDatabaseMissing('task_progress', [
            'task_id' => $this->task->id,
            'project_id' => $this->project->id,
            'progress_date' => $futureDate,
        ]);
    }

    public function test_date_validation_prevents_future_date_selection(): void
    {
        $this->actingAs($this->user);
        $futureDate = now()->addDays(1)->format('Y-m-d');
        $today = now()->format('Y-m-d');

        $component = Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('maxDate', $today)
            ->set('selectedDate', $futureDate);

        // After setting future date, it should be reset to today
        $component->assertSet('selectedDate', $today);
        $component->assertHasErrors('selectedDate');
    }

    public function test_can_update_existing_progress_for_same_date(): void
    {
        $this->actingAs($this->user);
        $today = now()->format('Y-m-d');

        // First save creates a new record
        $component = Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $today)
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => 25.0,
                    'notes' => 'Initial progress',
                ],
            ])
            ->call('saveProgress', $this->task->id)
            ->assertHasNoErrors();

        $initialCount = TaskProgress::count();
        // Note: Initial save triggers S-curve backfilling from project start date
        // So count will be > 1 (includes backfilled entries)
        $this->assertGreaterThan(0, $initialCount);

        // Second save with same date should update
        $component->set('progressData', [
            $this->task->id => [
                'percentage' => 80.0,
                'notes' => 'Updated progress',
            ],
        ])->call('saveProgress', $this->task->id)
            ->assertHasNoErrors();

        // Should still have same total count (update, not insert)
        $finalCount = TaskProgress::count();
        $this->assertEquals($initialCount, $finalCount);

        // Get the progress entry for today specifically
        $updatedProgress = TaskProgress::where('task_id', $this->task->id)
            ->whereDate('progress_date', $today)
            ->first();
        $this->assertEquals(80.0, $updatedProgress->percentage);
        $this->assertEquals('Updated progress', $updatedProgress->notes);
    }

    public function test_validates_percentage_range(): void
    {
        $this->actingAs($this->user);
        $today = now()->format('Y-m-d');

        // Test percentage over 100
        Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $today)
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => 150.0,
                    'notes' => 'Invalid percentage',
                ],
            ])
            ->call('saveProgress', $this->task->id)
            ->assertHasErrors('percentage');

        $this->assertDatabaseMissing('task_progress', [
            'task_id' => $this->task->id,
            'percentage' => 150.0,
        ]);
    }

    public function test_validates_negative_percentage(): void
    {
        $this->actingAs($this->user);
        $today = now()->format('Y-m-d');

        Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $today)
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => -10.0,
                    'notes' => 'Negative percentage',
                ],
            ])
            ->call('saveProgress', $this->task->id)
            ->assertHasErrors('percentage');

        $this->assertDatabaseMissing('task_progress', [
            'task_id' => $this->task->id,
            'percentage' => -10.0,
        ]);
    }

    public function test_loads_progress_data_for_selected_date(): void
    {
        $this->actingAs($this->user);
        $pastDate = now()->subDays(3)->format('Y-m-d');

        // First, save progress using the component (this ensures correct user_id is used)
        Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $pastDate)
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => 60.0,
                    'notes' => 'Past progress',
                ],
            ])
            ->call('saveProgress', $this->task->id)
            ->assertHasNoErrors();

        // Now test loading - create new component instance to simulate fresh load
        $component = Volt::test('progress.index')
            ->set('selectedProjectId', $this->project->id)
            ->set('selectedDate', $pastDate);

        // Call loadProgressData to reload the data
        $component->call('loadProgressData');

        $progressData = $component->get('progressData');

        $this->assertIsArray($progressData);
        $this->assertArrayHasKey($this->task->id, $progressData);
        $this->assertEquals(60.0, $progressData[$this->task->id]['percentage']);
        $this->assertEquals('Past progress', $progressData[$this->task->id]['notes']);
    }

    public function test_max_date_is_set_to_today_on_mount(): void
    {
        $this->actingAs($this->user);
        $today = now()->format('Y-m-d');

        Volt::test('progress.index')
            ->assertSet('maxDate', $today)
            ->assertSet('selectedDate', $today);
    }

    public function test_requires_project_selection_to_save_progress(): void
    {
        $this->actingAs($this->user);
        $today = now()->format('Y-m-d');

        Volt::test('progress.index')
            ->set('selectedProjectId', null)
            ->set('selectedDate', $today)
            ->set('progressData', [
                $this->task->id => [
                    'percentage' => 50.0,
                    'notes' => 'No project',
                ],
            ])
            ->call('saveProgress', $this->task->id);

        $this->assertDatabaseMissing('task_progress', [
            'task_id' => $this->task->id,
        ]);
    }
}
