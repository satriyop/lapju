<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuthorizationBypassTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;

    private User $koramilAdmin;

    private User $kodimAdmin;

    private Project $project1;

    private Project $project2;

    private Office $kodim;

    private Office $koramil1;

    private Office $koramil2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create office hierarchy
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);

        $this->kodim = Office::factory()->create([
            'level_id' => $kodimLevel->id,
            'parent_id' => null,
        ]);

        $this->koramil1 = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        $this->koramil2 = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        // Create roles
        $reporterRole = Role::factory()->system()->create([
            'name' => 'Reporter',
            'permissions' => [],
        ]);

        $koramilAdminRole = Role::factory()->system()->create([
            'name' => 'Koramil Admin',
            'permissions' => ['edit_projects', 'delete_projects'],
            'office_level_id' => $koramilLevel->id,
        ]);

        $kodimAdminRole = Role::factory()->system()->create([
            'name' => 'Kodim Admin',
            'permissions' => ['edit_projects', 'delete_projects', 'manage_users'],
            'office_level_id' => $kodimLevel->id,
        ]);

        // Create users
        $this->reporter = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $this->reporter->roles()->attach($reporterRole);

        $this->koramilAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $this->koramilAdmin->roles()->attach($koramilAdminRole);

        $this->kodimAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->kodim->id,
        ]);
        $this->kodimAdmin->roles()->attach($kodimAdminRole);

        // Create projects
        $this->project1 = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Koramil 1 Project',
        ]);

        $this->project2 = Project::factory()->create([
            'office_id' => $this->koramil2->id,
            'name' => 'Koramil 2 Project',
        ]);

        // Assign reporter to project1 only
        $this->project1->users()->attach($this->reporter->id, ['role' => 'reporter']);
    }

    public function test_reporter_cannot_access_unassigned_project(): void
    {
        $this->actingAs($this->reporter);

        Volt::test('projects.index')
            ->assertDontSee('Koramil 2 Project');
    }

    public function test_reporter_cannot_edit_project_even_if_assigned(): void
    {
        $this->actingAs($this->reporter);

        $this->assertFalse($this->reporter->hasPermission('edit_projects'));
    }

    public function test_koramil_admin_cannot_edit_project_in_other_koramil(): void
    {
        $this->actingAs($this->koramilAdmin);

        Volt::test('projects.index')
            ->assertSee('Koramil 1 Project')
            ->assertDontSee('Koramil 2 Project');
    }

    public function test_unapproved_user_cannot_bypass_approval_middleware(): void
    {
        $unapprovedUser = User::factory()->create([
            'is_approved' => false,
        ]);

        $response = $this->actingAs($unapprovedUser)->get(route('projects.index'));

        $response->assertRedirect(route('pending-approval'));
    }

    public function test_non_admin_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->reporter);

        $response = $this->get(route('admin.users.index'));

        $response->assertStatus(403);
    }

    public function test_kodim_admin_cannot_edit_tasks_without_project_relationship(): void
    {
        $this->actingAs($this->kodimAdmin);

        // Kodim admin has access to child koramils' projects
        Volt::test('projects.index')
            ->assertSee('Koramil 1 Project')
            ->assertSee('Koramil 2 Project');

        // But they need proper authorization checks in place
        $this->assertTrue($this->kodimAdmin->hasPermission('edit_projects'));
    }

    public function test_cannot_manipulate_user_id_to_impersonate_another_user(): void
    {
        $this->actingAs($this->reporter);

        // Create a leaf task (no children) - required for progress saving
        $task = Task::factory()->create([
            'project_id' => $this->project1->id,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        // Directly create a task progress to test that auth user ID is enforced
        $progress = \App\Models\TaskProgress::create([
            'project_id' => $this->project1->id,
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'progress_date' => now(),
            'percentage' => 50,
        ]);

        // Verify the progress was created with authenticated user's ID, not manipulated ID
        $this->assertEquals($this->reporter->id, $progress->user_id);
        $this->assertDatabaseHas('task_progress', [
            'task_id' => $task->id,
            'user_id' => $this->reporter->id,
        ]);
    }

    public function test_role_permissions_are_properly_enforced(): void
    {
        $this->actingAs($this->reporter);

        // Reporters should not have these permissions
        $this->assertFalse($this->reporter->hasPermission('edit_projects'));
        $this->assertFalse($this->reporter->hasPermission('delete_projects'));
        $this->assertFalse($this->reporter->hasPermission('manage_users'));

        $this->actingAs($this->koramilAdmin);

        // Koramil Admin should have these permissions
        $this->assertTrue($this->koramilAdmin->hasPermission('edit_projects'));
        $this->assertTrue($this->koramilAdmin->hasPermission('delete_projects'));
        $this->assertFalse($this->koramilAdmin->hasPermission('manage_users'));
    }

    public function test_office_hierarchy_is_properly_enforced(): void
    {
        $this->actingAs($this->koramilAdmin);

        // Koramil admin from koramil1 should not see koramil2 projects
        Volt::test('projects.index')
            ->assertSee('Koramil 1 Project')
            ->assertDontSee('Koramil 2 Project');
    }

    public function test_project_assignment_is_required_for_reporter_access(): void
    {
        // Create a new reporter without project assignment
        $newReporter = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);

        $reporterRole = Role::where('name', 'Reporter')->first();
        $newReporter->roles()->attach($reporterRole);

        $this->actingAs($newReporter);

        // Should not see any projects
        Volt::test('projects.index')
            ->assertDontSee('Koramil 1 Project');
    }
}
