<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProjectAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private OfficeLevel $kodimLevel;

    private OfficeLevel $koramilLevel;

    private Office $kodim;

    private Office $koramil1;

    private Office $koramil2;

    private Role $reporterRole;

    private Role $koramilAdminRole;

    private Role $kodimAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create office levels
        $this->kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $this->koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);

        // Create office hierarchy
        $this->kodim = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'parent_id' => null,
            'name' => 'Kodim 001',
        ]);

        $this->koramil1 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
            'name' => 'Koramil 001-01',
        ]);

        $this->koramil2 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
            'name' => 'Koramil 001-02',
        ]);

        // Create system roles
        $this->reporterRole = Role::factory()->system()->create([
            'name' => 'Reporter',
            'permissions' => [],
        ]);

        $this->koramilAdminRole = Role::factory()->system()->create([
            'name' => 'Koramil Admin',
            'permissions' => ['edit_projects', 'delete_projects'],
            'office_level_id' => $this->koramilLevel->id,
        ]);

        $this->kodimAdminRole = Role::factory()->system()->create([
            'name' => 'Kodim Admin',
            'permissions' => ['edit_projects', 'delete_projects', 'manage_users'],
            'office_level_id' => $this->kodimLevel->id,
        ]);
    }

    public function test_admin_can_see_all_projects(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $project1 = Project::factory()->create(['office_id' => $this->koramil1->id, 'name' => 'Project Koramil 1']);
        $project2 = Project::factory()->create(['office_id' => $this->koramil2->id, 'name' => 'Project Koramil 2']);

        $this->actingAs($admin);

        Volt::test('projects.index')
            ->assertSee('Project Koramil 1')
            ->assertSee('Project Koramil 2');
    }

    public function test_reporter_can_only_see_assigned_projects(): void
    {
        $reporter = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $reporter->roles()->attach($this->reporterRole);

        $assignedProject = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Assigned Project',
        ]);
        $assignedProject->users()->attach($reporter->id, ['role' => 'reporter']);

        $unassignedProject = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Unassigned Project',
        ]);

        $this->actingAs($reporter);

        Volt::test('projects.index')
            ->assertSee('Assigned Project')
            ->assertDontSee('Unassigned Project');
    }

    public function test_koramil_admin_can_see_projects_in_their_office(): void
    {
        $koramilAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $koramilAdmin->roles()->attach($this->koramilAdminRole);

        $ownOfficeProject = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Own Office Project',
        ]);

        $otherOfficeProject = Project::factory()->create([
            'office_id' => $this->koramil2->id,
            'name' => 'Other Office Project',
        ]);

        $this->actingAs($koramilAdmin);

        Volt::test('projects.index')
            ->assertSee('Own Office Project')
            ->assertDontSee('Other Office Project');
    }

    public function test_kodim_admin_can_see_projects_in_all_child_koramils(): void
    {
        $kodimAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->kodim->id,
        ]);
        $kodimAdmin->roles()->attach($this->kodimAdminRole);

        $koramil1Project = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Koramil 1 Project',
        ]);

        $koramil2Project = Project::factory()->create([
            'office_id' => $this->koramil2->id,
            'name' => 'Koramil 2 Project',
        ]);

        $this->actingAs($kodimAdmin);

        Volt::test('projects.index')
            ->assertSee('Koramil 1 Project')
            ->assertSee('Koramil 2 Project');
    }

    public function test_admin_can_edit_any_project(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $project = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Test Project',
        ]);

        $this->actingAs($admin);

        Volt::test('projects.index')
            ->call('edit', $project->id)
            ->set('name', 'Updated by Admin')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated by Admin',
        ]);
    }

    public function test_admin_can_delete_any_project(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $project = Project::factory()->create([
            'office_id' => $this->koramil1->id,
        ]);

        $this->actingAs($admin);

        Volt::test('projects.index')
            ->call('delete', $project->id);

        $this->assertDatabaseMissing('projects', [
            'id' => $project->id,
        ]);
    }

    public function test_koramil_admin_can_edit_projects_in_their_office(): void
    {
        $koramilAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $koramilAdmin->roles()->attach($this->koramilAdminRole);

        $project = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Office Project',
        ]);

        $this->actingAs($koramilAdmin);

        Volt::test('projects.index')
            ->call('edit', $project->id)
            ->set('name', 'Updated by Koramil Admin')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated by Koramil Admin',
        ]);
    }

    public function test_koramil_admin_cannot_see_projects_in_other_offices(): void
    {
        $koramilAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $koramilAdmin->roles()->attach($this->koramilAdminRole);

        $ownOfficeProject = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Own Office Project',
        ]);

        $otherOfficeProject = Project::factory()->create([
            'office_id' => $this->koramil2->id,
            'name' => 'Other Office Project',
        ]);

        $this->actingAs($koramilAdmin);

        // They should only see projects in their own office
        Volt::test('projects.index')
            ->assertSee('Own Office Project')
            ->assertDontSee('Other Office Project');
    }

    public function test_kodim_admin_can_edit_projects_in_child_koramils(): void
    {
        $kodimAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->kodim->id,
        ]);
        $kodimAdmin->roles()->attach($this->kodimAdminRole);

        $project = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Child Koramil Project',
        ]);

        $this->actingAs($kodimAdmin);

        Volt::test('projects.index')
            ->call('edit', $project->id)
            ->set('name', 'Updated by Kodim Admin')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated by Kodim Admin',
        ]);
    }

    public function test_reporter_does_not_see_manage_buttons_on_assigned_projects(): void
    {
        $reporter = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $reporter->roles()->attach($this->reporterRole);

        $project = Project::factory()->create([
            'office_id' => $this->koramil1->id,
            'name' => 'Reporter Assigned Project XYZ123',
        ]);
        $project->users()->attach($reporter->id, ['role' => 'reporter']);

        $this->actingAs($reporter);

        // Reporter should see the project and actual progress percentage (not edit/delete buttons)
        $html = Volt::test('projects.index')->html();

        $this->assertStringContainsString('Reporter Assigned Project XYZ123', $html);
        $this->assertStringContainsString('Actual progress %', $html);  // This is what reporters see
        $this->assertStringNotContainsString('Actions', $html);  // No Actions column header for reporters
    }

    public function test_reporter_has_no_edit_or_delete_permissions(): void
    {
        $reporter = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $reporter->roles()->attach($this->reporterRole);

        $this->actingAs($reporter);

        // Reporters don't have edit_projects permission
        $this->assertFalse($reporter->hasPermission('edit_projects'));
        $this->assertFalse($reporter->hasPermission('delete_projects'));
    }

    public function test_reporter_can_create_project_in_their_office(): void
    {
        $reporter = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $reporter->roles()->attach($this->reporterRole);

        $partner = Partner::factory()->create();
        $location = Location::factory()->create();

        $this->actingAs($reporter);

        Volt::test('projects.index')
            ->set('partnerId', $partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil1->id)
            ->set('locationId', $location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('name', 'Reporter Created Project')
            ->set('status', 'planning')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Reporter Created Project',
            'office_id' => $this->koramil1->id,
        ]);
    }

    public function test_user_with_wildcard_permission_can_manage_all_projects(): void
    {
        $superUser = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);

        $superRole = Role::factory()->create([
            'name' => 'Super Manager',
            'permissions' => ['*'],
        ]);
        $superUser->roles()->attach($superRole);

        $project = Project::factory()->create([
            'office_id' => $this->koramil2->id,
            'name' => 'Any Project',
        ]);

        $this->actingAs($superUser);

        Volt::test('projects.index')
            ->call('edit', $project->id)
            ->set('name', 'Updated by Super User')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated by Super User',
        ]);
    }

    public function test_koramil_admin_can_delete_projects_in_their_office(): void
    {
        $koramilAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $koramilAdmin->roles()->attach($this->koramilAdminRole);

        $project = Project::factory()->create([
            'office_id' => $this->koramil1->id,
        ]);

        $this->actingAs($koramilAdmin);

        Volt::test('projects.index')
            ->call('delete', $project->id);

        $this->assertDatabaseMissing('projects', [
            'id' => $project->id,
        ]);
    }

    public function test_koramil_admin_has_edit_permission(): void
    {
        $koramilAdmin = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $koramilAdmin->roles()->attach($this->koramilAdminRole);

        $this->actingAs($koramilAdmin);

        // Koramil Admin should have edit_projects permission
        $this->assertTrue($koramilAdmin->hasPermission('edit_projects'));
        $this->assertTrue($koramilAdmin->hasPermission('delete_projects'));
    }
}
