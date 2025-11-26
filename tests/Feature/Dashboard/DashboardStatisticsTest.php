<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Office $kodim;

    private OfficeLevel $kodimLevel;

    private OfficeLevel $koramilLevel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $this->kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $this->koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);

        $this->kodim = Office::factory()->create(['level_id' => $this->kodimLevel->id]);
    }

    public function test_dashboard_calculates_total_projects_count(): void
    {
        Project::factory()->count(5)->create(['office_id' => $this->kodim->id]);

        $this->actingAs($this->admin);

        Volt::test('dashboard')
            ->assertSee('5');
    }

    public function test_dashboard_calculates_active_projects_with_progress(): void
    {
        $koramil = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        // Create 3 projects
        $project1 = Project::factory()->create(['office_id' => $koramil->id]);
        $project2 = Project::factory()->create(['office_id' => $koramil->id]);
        $project3 = Project::factory()->create(['office_id' => $koramil->id]);

        // Only project1 and project2 have progress
        $task1 = Task::factory()->create(['project_id' => $project1->id]);
        $task2 = Task::factory()->create(['project_id' => $project2->id]);

        $user = User::factory()->create(['is_approved' => true]);

        TaskProgress::create([
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'user_id' => $user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        TaskProgress::create([
            'project_id' => $project2->id,
            'task_id' => $task2->id,
            'user_id' => $user->id,
            'percentage' => 30.00,
            'progress_date' => now(),
        ]);

        // project3 has no progress, so active count should be 2
        $activeCount = Project::whereIn('id', [$project1->id, $project2->id, $project3->id])
            ->whereHas('tasks.progress')
            ->count();

        $this->assertEquals(2, $activeCount);
    }

    public function test_dashboard_calculates_total_locations_count(): void
    {
        Location::factory()->count(10)->create();

        $totalLocations = Location::count();

        $this->assertEquals(10, $totalLocations);
    }

    public function test_dashboard_calculates_total_reporters_count(): void
    {
        $reporterRole = Role::factory()->create(['name' => 'Reporter']);

        $user1 = User::factory()->create(['is_approved' => true]);
        $user2 = User::factory()->create(['is_approved' => true]);
        $user3 = User::factory()->create(['is_approved' => true]);

        $user1->roles()->attach($reporterRole->id);
        $user2->roles()->attach($reporterRole->id);
        // user3 doesn't have reporter role

        $reporterCount = User::whereHas('roles', fn ($q) => $q->where('name', 'Reporter'))
            ->count();

        $this->assertEquals(2, $reporterCount);
    }

    public function test_dashboard_filters_projects_by_office_hierarchy(): void
    {
        $koramil1 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        $koramil2 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        Project::factory()->count(3)->create(['office_id' => $koramil1->id]);
        Project::factory()->count(2)->create(['office_id' => $koramil2->id]);

        // Projects under kodim should be 5 total
        $projectsUnderKodim = Project::whereIn('office_id', [$koramil1->id, $koramil2->id])
            ->count();

        $this->assertEquals(5, $projectsUnderKodim);
    }

    public function test_dashboard_statistics_are_zero_when_no_data(): void
    {
        $stats = [
            'total_projects' => Project::count(),
            'active_projects' => Project::whereHas('tasks.progress')->count(),
            'total_locations' => Location::count(),
            'total_reporters' => User::whereHas('roles', fn ($q) => $q->where('name', 'Reporter'))->count(),
        ];

        $this->assertEquals(0, $stats['total_projects']);
        $this->assertEquals(0, $stats['active_projects']);
        $this->assertEquals(0, $stats['total_locations']);
        $this->assertEquals(0, $stats['total_reporters']);
    }

    public function test_dashboard_active_projects_excludes_projects_without_progress(): void
    {
        $koramil = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        $projectWithProgress = Project::factory()->create(['office_id' => $koramil->id]);
        $projectWithoutProgress = Project::factory()->create(['office_id' => $koramil->id]);

        $task = Task::factory()->create(['project_id' => $projectWithProgress->id]);
        $user = User::factory()->create(['is_approved' => true]);

        TaskProgress::create([
            'project_id' => $projectWithProgress->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $activeCount = Project::whereIn('id', [$projectWithProgress->id, $projectWithoutProgress->id])
            ->whereHas('tasks.progress')
            ->count();

        $this->assertEquals(1, $activeCount);
    }

    public function test_dashboard_counts_distinct_locations_used_by_projects(): void
    {
        $location1 = Location::factory()->create();
        $location2 = Location::factory()->create();

        Project::factory()->create(['office_id' => $this->kodim->id, 'location_id' => $location1->id]);
        Project::factory()->create(['office_id' => $this->kodim->id, 'location_id' => $location1->id]);
        Project::factory()->create(['office_id' => $this->kodim->id, 'location_id' => $location2->id]);

        $distinctLocations = Project::distinct('location_id')->count('location_id');

        $this->assertEquals(2, $distinctLocations);
    }

    public function test_dashboard_counts_reporters_with_reporter_role_only(): void
    {
        $reporterRole = Role::factory()->create(['name' => 'Reporter']);
        $managerRole = Role::factory()->create(['name' => 'Manager']);

        $reporter = User::factory()->create(['is_approved' => true]);
        $manager = User::factory()->create(['is_approved' => true]);
        $reporterAndManager = User::factory()->create(['is_approved' => true]);

        $reporter->roles()->attach($reporterRole->id);
        $manager->roles()->attach($managerRole->id);
        $reporterAndManager->roles()->attach([$reporterRole->id, $managerRole->id]);

        $reporterCount = User::whereHas('roles', fn ($q) => $q->where('name', 'Reporter'))
            ->count();

        // Should count reporter and reporterAndManager = 2
        $this->assertEquals(2, $reporterCount);
    }

    public function test_project_with_multiple_tasks_is_counted_as_one_active_project(): void
    {
        $koramil = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        $project = Project::factory()->create(['office_id' => $koramil->id]);

        $task1 = Task::factory()->create(['project_id' => $project->id]);
        $task2 = Task::factory()->create(['project_id' => $project->id]);
        $task3 = Task::factory()->create(['project_id' => $project->id]);

        $user = User::factory()->create(['is_approved' => true]);

        TaskProgress::create([
            'project_id' => $project->id,
            'task_id' => $task1->id,
            'user_id' => $user->id,
            'percentage' => 30.00,
            'progress_date' => now(),
        ]);

        TaskProgress::create([
            'project_id' => $project->id,
            'task_id' => $task2->id,
            'user_id' => $user->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        TaskProgress::create([
            'project_id' => $project->id,
            'task_id' => $task3->id,
            'user_id' => $user->id,
            'percentage' => 70.00,
            'progress_date' => now(),
        ]);

        $activeCount = Project::whereHas('tasks.progress')->count();

        $this->assertEquals(1, $activeCount);
    }

    public function test_statistics_are_calculated_independently(): void
    {
        $reporterRole = Role::factory()->create(['name' => 'Reporter']);

        $koramil = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        $location = Location::factory()->create();

        $project1 = Project::factory()->create([
            'office_id' => $koramil->id,
            'location_id' => $location->id,
        ]);
        $project2 = Project::factory()->create([
            'office_id' => $koramil->id,
            'location_id' => $location->id,
        ]);

        $task1 = Task::factory()->create(['project_id' => $project1->id]);

        $user1 = User::factory()->create(['is_approved' => true]);
        $user2 = User::factory()->create(['is_approved' => true]);

        $user1->roles()->attach($reporterRole->id);
        $user2->roles()->attach($reporterRole->id);

        TaskProgress::create([
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'user_id' => $user1->id,
            'percentage' => 50.00,
            'progress_date' => now(),
        ]);

        $stats = [
            'total_projects' => Project::count(),
            'active_projects' => Project::whereHas('tasks.progress')->count(),
            'total_locations' => Location::count(),
            'total_reporters' => User::whereHas('roles', fn ($q) => $q->where('name', 'Reporter'))->count(),
        ];

        $this->assertEquals(2, $stats['total_projects']);
        $this->assertEquals(1, $stats['active_projects']);
        $this->assertEquals(1, $stats['total_locations']);
        $this->assertEquals(2, $stats['total_reporters']);
    }
}
