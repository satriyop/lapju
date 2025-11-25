<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectUserAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $user1;

    private User $user2;

    private Office $office;

    protected function setUp(): void
    {
        parent::setUp();

        $level = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $this->office = Office::factory()->create(['level_id' => $level->id]);

        $this->user1 = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->office->id,
        ]);

        $this->user2 = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->office->id,
        ]);

        $this->project = Project::factory()->create([
            'office_id' => $this->office->id,
        ]);
    }

    public function test_user_can_be_assigned_to_project(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $this->assertDatabaseHas('project_user', [
            'project_id' => $this->project->id,
            'user_id' => $this->user1->id,
            'role' => 'reporter',
        ]);
    }

    public function test_multiple_users_can_be_assigned_to_project(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);
        $this->project->users()->attach($this->user2->id, ['role' => 'reporter']);

        $this->assertTrue($this->project->users->contains($this->user1));
        $this->assertTrue($this->project->users->contains($this->user2));
        $this->assertCount(2, $this->project->users);
    }

    public function test_user_can_be_removed_from_project(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $this->assertTrue($this->project->users->contains($this->user1));

        $this->project->users()->detach($this->user1->id);

        $this->project->refresh();
        $this->assertFalse($this->project->users->contains($this->user1));
    }

    public function test_project_user_pivot_has_role_field(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'supervisor']);

        $pivotData = $this->project->users()->where('user_id', $this->user1->id)->first()->pivot;

        $this->assertEquals('supervisor', $pivotData->role);
    }

    public function test_user_can_have_different_roles_in_different_projects(): void
    {
        $project2 = Project::factory()->create([
            'office_id' => $this->office->id,
        ]);

        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);
        $project2->users()->attach($this->user1->id, ['role' => 'supervisor']);

        $role1 = $this->project->users()->where('user_id', $this->user1->id)->first()->pivot->role;
        $role2 = $project2->users()->where('user_id', $this->user1->id)->first()->pivot->role;

        $this->assertEquals('reporter', $role1);
        $this->assertEquals('supervisor', $role2);
    }

    public function test_same_user_cannot_be_assigned_twice_to_same_project(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);
    }

    public function test_project_user_assignment_has_timestamps(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $pivot = $this->project->users()->where('user_id', $this->user1->id)->first()->pivot;

        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
    }

    public function test_user_can_access_assigned_projects(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $user = User::with('projects')->find($this->user1->id);

        $this->assertTrue($user->projects->contains($this->project));
    }

    public function test_project_can_access_assigned_users(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);
        $this->project->users()->attach($this->user2->id, ['role' => 'supervisor']);

        $project = Project::with('users')->find($this->project->id);

        $this->assertCount(2, $project->users);
        $this->assertTrue($project->users->contains($this->user1));
        $this->assertTrue($project->users->contains($this->user2));
    }

    public function test_deleting_project_cascades_to_assignments(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $projectId = $this->project->id;
        $this->project->delete();

        $this->assertDatabaseMissing('project_user', [
            'project_id' => $projectId,
            'user_id' => $this->user1->id,
        ]);
    }

    public function test_deleting_user_cascades_to_assignments(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $userId = $this->user1->id;
        $this->user1->delete();

        $this->assertDatabaseMissing('project_user', [
            'project_id' => $this->project->id,
            'user_id' => $userId,
        ]);
    }

    public function test_user_assignment_role_can_be_updated(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $this->project->users()->updateExistingPivot($this->user1->id, ['role' => 'manager']);

        $pivot = $this->project->users()->where('user_id', $this->user1->id)->first()->pivot;

        $this->assertEquals('manager', $pivot->role);
    }

    public function test_project_with_no_users_returns_empty_collection(): void
    {
        $this->assertCount(0, $this->project->users);
    }

    public function test_user_with_no_projects_returns_empty_collection(): void
    {
        $this->assertCount(0, $this->user1->projects);
    }

    public function test_bulk_assign_users_to_project(): void
    {
        $user3 = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->office->id,
        ]);

        $assignments = [
            $this->user1->id => ['role' => 'reporter'],
            $this->user2->id => ['role' => 'reporter'],
            $user3->id => ['role' => 'supervisor'],
        ];

        $this->project->users()->attach($assignments);

        $this->assertCount(3, $this->project->users);
    }

    public function test_sync_users_replaces_existing_assignments(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);
        $this->project->users()->attach($this->user2->id, ['role' => 'reporter']);

        $user3 = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->office->id,
        ]);

        $this->project->users()->sync([
            $user3->id => ['role' => 'supervisor'],
        ]);

        $this->project->refresh();

        $this->assertCount(1, $this->project->users);
        $this->assertTrue($this->project->users->contains($user3));
        $this->assertFalse($this->project->users->contains($this->user1));
        $this->assertFalse($this->project->users->contains($this->user2));
    }

    public function test_sync_without_detaching_adds_new_assignments(): void
    {
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);

        $user3 = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->office->id,
        ]);

        $this->project->users()->syncWithoutDetaching([
            $user3->id => ['role' => 'supervisor'],
        ]);

        $this->project->refresh();

        $this->assertCount(2, $this->project->users);
        $this->assertTrue($this->project->users->contains($this->user1));
        $this->assertTrue($this->project->users->contains($user3));
    }

    public function test_assignment_role_defaults_to_member(): void
    {
        $this->project->users()->attach($this->user1->id);

        $pivot = $this->project->users()->where('user_id', $this->user1->id)->first()->pivot;

        $this->assertEquals('member', $pivot->role);
    }

    public function test_user_can_be_assigned_to_multiple_projects(): void
    {
        $project2 = Project::factory()->create([
            'office_id' => $this->office->id,
        ]);

        $project3 = Project::factory()->create([
            'office_id' => $this->office->id,
        ]);

        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);
        $project2->users()->attach($this->user1->id, ['role' => 'reporter']);
        $project3->users()->attach($this->user1->id, ['role' => 'supervisor']);

        $user = User::with('projects')->find($this->user1->id);

        $this->assertCount(3, $user->projects);
    }

    public function test_project_user_relationship_is_many_to_many(): void
    {
        // Multiple users to one project
        $this->project->users()->attach($this->user1->id, ['role' => 'reporter']);
        $this->project->users()->attach($this->user2->id, ['role' => 'reporter']);

        // One user to multiple projects
        $project2 = Project::factory()->create([
            'office_id' => $this->office->id,
        ]);
        $project2->users()->attach($this->user1->id, ['role' => 'supervisor']);

        $this->assertCount(2, $this->project->users);
        $this->assertCount(2, $this->user1->projects);
    }
}
