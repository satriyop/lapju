<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OfficeLevel $kodimLevel;

    private OfficeLevel $koramilLevel;

    private Office $kodim;

    private Office $koramil;

    private Role $reporterRole;

    private Role $managerRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        // Create office hierarchy
        $this->kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $this->koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $this->kodim = Office::factory()->create(['level_id' => $this->kodimLevel->id]);
        $this->koramil = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        // Create roles
        $this->reporterRole = Role::factory()->system()->create([
            'name' => 'Reporter',
            'permissions' => ['view_projects'],
            'office_level_id' => $this->koramilLevel->id,
        ]);

        $this->managerRole = Role::factory()->system()->create([
            'name' => 'Manager',
            'permissions' => ['view_projects', 'edit_projects'],
            'office_level_id' => $this->kodimLevel->id,
        ]);
    }

    public function test_admin_can_access_user_management_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertStatus(200);
    }

    public function test_non_admin_without_permission_cannot_access_user_management(): void
    {
        $regularUser = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $this->actingAs($regularUser)
            ->get(route('admin.users.index'))
            ->assertStatus(403);
    }

    public function test_admin_can_create_user_with_role(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true)
            ->set('createName', 'New User')
            ->set('createEmail', 'newuser@example.com')
            ->set('createNrp', 'NRP12345')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->set('createIsApproved', true)
            ->set('createIsAdmin', false)
            ->call('createUser')
            ->assertHasNoErrors()
            ->assertSet('showCreateModal', false);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('New User', $user->name);
        $this->assertEquals('NRP12345', $user->nrp);
        $this->assertEquals('08123456789', $user->phone);
        $this->assertEquals($this->koramil->id, $user->office_id);
        $this->assertTrue($user->is_approved);
        $this->assertFalse($user->is_admin);
        $this->assertEquals($this->admin->id, $user->approved_by);
        $this->assertNotNull($user->approved_at);
        $this->assertTrue($user->hasRole('Reporter'));
    }

    public function test_admin_can_create_user_with_admin_privileges(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Admin User')
            ->set('createEmail', 'admin@example.com')
            ->set('createNrp', 'ADMIN123')
            ->set('createPhone', '08987654321')
            ->set('createRoleId', $this->managerRole->id)
            ->set('createOfficeId', $this->kodim->id)
            ->set('createPassword', 'securepassword')
            ->set('createPasswordConfirmation', 'securepassword')
            ->set('createIsApproved', true)
            ->set('createIsAdmin', true)
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'admin@example.com')->first();
        $this->assertTrue($user->is_admin);
    }

    public function test_admin_can_create_pending_user(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Pending User')
            ->set('createEmail', 'pending@example.com')
            ->set('createNrp', 'PENDING123')
            ->set('createPhone', '08111222333')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->set('createIsApproved', false)
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'pending@example.com')->first();
        $this->assertFalse($user->is_approved);
        $this->assertNull($user->approved_by);
        $this->assertNull($user->approved_at);
        $this->assertFalse($user->hasRole('Reporter'));
    }

    public function test_cascading_role_office_selection(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->assertSet('createOfficeId', $this->koramil->id)
            ->set('createRoleId', $this->managerRole->id)
            ->assertSet('createOfficeId', null);
    }

    public function test_create_user_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', '')
            ->set('createEmail', '')
            ->set('createNrp', '')
            ->set('createPhone', '')
            ->set('createPassword', '')
            ->call('createUser')
            ->assertHasErrors([
                'createName' => 'required',
                'createEmail' => 'required',
                'createNrp' => 'required',
                'createPhone' => 'required',
                'createRoleId' => 'required',
                'createOfficeId' => 'required',
                'createPassword' => 'required',
            ]);
    }

    public function test_create_user_validates_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'taken@example.com')
            ->set('createNrp', 'NRP999')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createEmail' => 'unique']);
    }

    public function test_create_user_validates_unique_nrp(): void
    {
        User::factory()->create(['nrp' => 'TAKEN123']);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'unique@example.com')
            ->set('createNrp', 'TAKEN123')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createNrp' => 'unique']);
    }

    public function test_create_user_validates_password_confirmation(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'test@example.com')
            ->set('createNrp', 'NRP888')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'differentpassword')
            ->call('createUser')
            ->assertHasErrors(['createPassword' => 'same']);
    }

    public function test_create_user_validates_phone_format(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'test@example.com')
            ->set('createNrp', 'NRP777')
            ->set('createPhone', 'invalid-phone!')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createPhone' => 'regex']);
    }

    public function test_create_user_validates_office_matches_role_level(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'test@example.com')
            ->set('createNrp', 'NRP666')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->kodim->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createOfficeId']);
    }

    public function test_open_create_modal_resets_form(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->set('createName', 'Old Name')
            ->set('createEmail', 'old@example.com')
            ->call('openCreateModal')
            ->assertSet('createName', '')
            ->assertSet('createEmail', '')
            ->assertSet('createNrp', '')
            ->assertSet('createPhone', '')
            ->assertSet('createRoleId', null)
            ->assertSet('createOfficeId', null)
            ->assertSet('createPassword', '')
            ->assertSet('createPasswordConfirmation', '')
            ->assertSet('createIsAdmin', false)
            ->assertSet('createIsApproved', true);
    }

    public function test_created_user_password_is_hashed(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Password Test')
            ->set('createEmail', 'passtest@example.com')
            ->set('createNrp', 'NRP555')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'passtest@example.com')->first();
        $this->assertNotEquals('password123', $user->password);
        $this->assertTrue(password_verify('password123', $user->password));
    }

    public function test_approved_user_gets_assigned_role(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Reporter User')
            ->set('createEmail', 'reporter@example.com')
            ->set('createNrp', 'NRP444')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->set('createIsApproved', true)
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'reporter@example.com')->first();
        $this->assertTrue($user->hasRole('Reporter'));
        $this->assertTrue($user->roles->contains($this->reporterRole));
    }

    public function test_pending_user_does_not_get_role_until_approved(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Pending User')
            ->set('createEmail', 'pending2@example.com')
            ->set('createNrp', 'NRP333')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->reporterRole->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->set('createIsApproved', false)
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'pending2@example.com')->first();
        $this->assertFalse($user->hasRole('Reporter'));
        $this->assertCount(0, $user->roles);
    }

    public function test_approving_koramil_user_assigns_reporter_role(): void
    {
        $pendingUser = User::factory()->create([
            'is_approved' => false,
            'office_id' => $this->koramil->id,
        ]);

        $this->assertFalse($pendingUser->hasRole('Reporter'));

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('approveUser', $pendingUser->id);

        $pendingUser->refresh();
        $this->assertTrue($pendingUser->is_approved);
        $this->assertTrue($pendingUser->hasRole('Reporter'));
        $this->assertNotNull($pendingUser->approved_at);
        $this->assertEquals($this->admin->id, $pendingUser->approved_by);
    }

    public function test_role_assignment_respects_office_level(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Manager User')
            ->set('createEmail', 'manager@example.com')
            ->set('createNrp', 'NRP222')
            ->set('createPhone', '08123456789')
            ->set('createRoleId', $this->managerRole->id)
            ->set('createOfficeId', $this->kodim->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->set('createIsApproved', true)
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'manager@example.com')->first();
        $this->assertTrue($user->hasRole('Manager'));
        $this->assertEquals($this->kodim->id, $user->office_id);
    }

    public function test_deleting_user_without_projects_works_directly(): void
    {
        $userToDelete = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('deleteUser', $userToDelete->id)
            ->assertSet('showReassignModal', false);

        $this->assertDatabaseMissing('users', ['id' => $userToDelete->id]);
    }

    public function test_deleting_user_with_projects_shows_reassignment_modal(): void
    {
        $userToDelete = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $project = Project::factory()->create(['office_id' => $this->koramil->id]);
        $project->users()->attach($userToDelete->id);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('deleteUser', $userToDelete->id)
            ->assertSet('showReassignModal', true)
            ->assertSet('deletingUserId', $userToDelete->id);

        // User should still exist
        $this->assertDatabaseHas('users', ['id' => $userToDelete->id]);
    }

    public function test_reassignment_modal_validates_all_projects_assigned(): void
    {
        $userToDelete = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $project1 = Project::factory()->create(['office_id' => $this->koramil->id]);
        $project2 = Project::factory()->create(['office_id' => $this->koramil->id]);
        $project1->users()->attach($userToDelete->id);
        $project2->users()->attach($userToDelete->id);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('prepareUserDeletion', $userToDelete->id)
            ->assertSet('showReassignModal', true)
            ->call('reassignAndDeleteUser')
            ->assertHasErrors(['projectReassignments']);

        // User should still exist
        $this->assertDatabaseHas('users', ['id' => $userToDelete->id]);
    }

    public function test_successful_project_reassignment_and_user_deletion(): void
    {
        $userToDelete = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $replacementUser = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $project = Project::factory()->create(['office_id' => $this->koramil->id]);
        $project->users()->attach($userToDelete->id);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('prepareUserDeletion', $userToDelete->id)
            ->assertSet('showReassignModal', true)
            ->set("projectReassignments.{$project->id}", $replacementUser->id)
            ->call('reassignAndDeleteUser')
            ->assertHasNoErrors()
            ->assertSet('showReassignModal', false);

        // User should be deleted
        $this->assertDatabaseMissing('users', ['id' => $userToDelete->id]);

        // Project should now be assigned to replacement user
        $this->assertTrue($project->users()->where('users.id', $replacementUser->id)->exists());
        $this->assertFalse($project->users()->where('users.id', $userToDelete->id)->exists());
    }

    public function test_reassignment_preserves_other_project_users(): void
    {
        $userToDelete = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $existingUser = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $replacementUser = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $project = Project::factory()->create(['office_id' => $this->koramil->id]);
        $project->users()->attach([$userToDelete->id, $existingUser->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('prepareUserDeletion', $userToDelete->id)
            ->set("projectReassignments.{$project->id}", $replacementUser->id)
            ->call('reassignAndDeleteUser')
            ->assertHasNoErrors();

        // Existing user should still be assigned
        $this->assertTrue($project->users()->where('users.id', $existingUser->id)->exists());

        // Replacement user should be assigned
        $this->assertTrue($project->users()->where('users.id', $replacementUser->id)->exists());

        // Deleted user should not be assigned
        $this->assertFalse($project->users()->where('users.id', $userToDelete->id)->exists());
    }

    public function test_cannot_delete_self(): void
    {
        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('deleteUser', $this->admin->id);

        // Admin should still exist
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }
}
