<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private OfficeLevel $koramLevel;

    private OfficeLevel $kodimLevel;

    private Office $kodim1;

    private Office $kodim2;

    private Office $koramil1;

    private Office $koramil2;

    private Role $reporterRole;

    private Role $kodimAdminRole;

    private Role $viewerRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        // Create office hierarchy - Koramil (level 4) is CHILD of Kodim (level 3)
        $this->kodimLevel = OfficeLevel::factory()->create([
            'level' => 3,
            'name' => 'Kodim',
        ]);

        $this->koramLevel = OfficeLevel::factory()->create([
            'level' => 4,
            'name' => 'Koramil',
        ]);

        // Two Kodims
        $this->kodim1 = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'name' => 'Kodim 0735',
        ]);

        $this->kodim2 = Office::factory()->create([
            'level_id' => $this->kodimLevel->id,
            'name' => 'Kodim 0736',
        ]);

        // Two Koramils under kodim1
        $this->koramil1 = Office::factory()->create([
            'level_id' => $this->koramLevel->id,
            'parent_id' => $this->kodim1->id,
            'name' => 'Koramil 01',
        ]);

        $this->koramil2 = Office::factory()->create([
            'level_id' => $this->koramLevel->id,
            'parent_id' => $this->kodim1->id,
            'name' => 'Koramil 02',
        ]);

        // Create roles with office level restrictions
        $this->reporterRole = Role::factory()->system()->create([
            'name' => 'Reporter',
            'permissions' => ['view_projects', 'create_progress'],
            'office_level_id' => $this->koramLevel->id, // Only for Koramil
        ]);

        $this->kodimAdminRole = Role::factory()->system()->create([
            'name' => 'Kodim Admin',
            'permissions' => ['view_projects', 'edit_projects', 'manage_users'],
            'office_level_id' => $this->kodimLevel->id, // Only for Kodim
        ]);

        $this->viewerRole = Role::factory()->system()->create([
            'name' => 'Viewer',
            'permissions' => ['view_projects'],
            'office_level_id' => null, // Can be assigned at any level
        ]);
    }

    // A. Role Assignment During Approval

    public function test_approving_kodim_user_assigns_kodim_admin_role(): void
    {
        $pendingUser = User::factory()->create([
            'is_approved' => false,
            'office_id' => $this->kodim1->id,
        ]);

        $this->assertFalse($pendingUser->hasRole('Kodim Admin'));

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('approveUser', $pendingUser->id);

        $pendingUser->refresh();
        $this->assertTrue($pendingUser->is_approved);
        $this->assertTrue($pendingUser->hasRole('Kodim Admin'));
        $this->assertFalse($pendingUser->hasRole('Reporter'));
        $this->assertNotNull($pendingUser->approved_at);
        $this->assertEquals($this->admin->id, $pendingUser->approved_by);
    }

    public function test_approving_koramil_user_assigns_reporter_role(): void
    {
        $pendingUser = User::factory()->create([
            'is_approved' => false,
            'office_id' => $this->koramil1->id,
        ]);

        $this->assertFalse($pendingUser->hasRole('Reporter'));

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('approveUser', $pendingUser->id);

        $pendingUser->refresh();
        $this->assertTrue($pendingUser->is_approved);
        $this->assertTrue($pendingUser->hasRole('Reporter'));
        $this->assertFalse($pendingUser->hasRole('Kodim Admin'));
    }

    public function test_approving_user_does_not_assign_role_if_role_doesnt_exist(): void
    {
        // Create user at a level without a corresponding role
        $remLevel = OfficeLevel::factory()->create(['level' => 2, 'name' => 'Korem']);
        $remOffice = Office::factory()->create(['level_id' => $remLevel->id]);

        $pendingUser = User::factory()->create([
            'is_approved' => false,
            'office_id' => $remOffice->id,
        ]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('approveUser', $pendingUser->id);

        $pendingUser->refresh();
        $this->assertTrue($pendingUser->is_approved);
        $this->assertCount(0, $pendingUser->roles);
    }

    // B. Role Editing - Upgrades

    public function test_reporter_can_be_upgraded_to_kodim_admin_when_office_changes(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $user->roles()->attach($this->reporterRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editOfficeId', $this->kodim1->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertEquals($this->kodim1->id, $user->office_id);
        $this->assertTrue($user->hasRole('Kodim Admin'));
        $this->assertFalse($user->hasRole('Reporter'));
    }

    public function test_reporter_cannot_get_kodim_admin_role_without_changing_office(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $user->roles()->attach($this->reporterRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id]) // Try to add Kodim Admin without changing office
            ->call('saveUser')
            ->assertHasErrors(['editRoleIds']);

        $user->refresh();
        $this->assertEquals($this->koramil1->id, $user->office_id);
        $this->assertTrue($user->hasRole('Reporter'));
        $this->assertFalse($user->hasRole('Kodim Admin'));
    }

    public function test_user_can_have_role_upgraded_when_office_changes_to_matching_level(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $user->roles()->attach($this->reporterRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        // Change office AND role in same operation
        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->assertSet('editOfficeId', $this->koramil1->id)
            ->assertSet('editRoleIds', [$this->reporterRole->id])
            ->set('editOfficeId', $this->kodim1->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id])
            ->call('saveUser')
            ->assertHasNoErrors()
            ->assertSet('showEditModal', false);

        $user->refresh();
        $this->assertEquals($this->kodim1->id, $user->office_id);
        $this->assertTrue($user->hasRole('Kodim Admin'));
        $this->assertFalse($user->hasRole('Reporter'));
    }

    // C. Role Editing - Downgrades

    public function test_kodim_admin_can_be_downgraded_to_reporter_when_office_changes(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);
        $user->roles()->attach($this->kodimAdminRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editOfficeId', $this->koramil1->id)
            ->set('editRoleIds', [$this->reporterRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertEquals($this->koramil1->id, $user->office_id);
        $this->assertTrue($user->hasRole('Reporter'));
        $this->assertFalse($user->hasRole('Kodim Admin'));
    }

    public function test_kodim_admin_cannot_get_reporter_role_without_changing_office(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);
        $user->roles()->attach($this->kodimAdminRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->reporterRole->id]) // Try to add Reporter without changing office
            ->call('saveUser')
            ->assertHasErrors(['editRoleIds']);

        $user->refresh();
        $this->assertEquals($this->kodim1->id, $user->office_id);
        $this->assertTrue($user->hasRole('Kodim Admin'));
        $this->assertFalse($user->hasRole('Reporter'));
    }

    // D. Office-Only Changes

    public function test_reporter_can_move_between_koramils_keeping_same_role(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $user->roles()->attach($this->reporterRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editOfficeId', $this->koramil2->id)
            ->set('editRoleIds', [$this->reporterRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertEquals($this->koramil2->id, $user->office_id);
        $this->assertTrue($user->hasRole('Reporter'));
    }

    public function test_kodim_admin_can_move_between_kodims_keeping_same_role(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);
        $user->roles()->attach($this->kodimAdminRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editOfficeId', $this->kodim2->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertEquals($this->kodim2->id, $user->office_id);
        $this->assertTrue($user->hasRole('Kodim Admin'));
    }

    // E. Multi-Role Management

    public function test_kodim_admin_can_add_viewer_role(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);
        $user->roles()->attach($this->kodimAdminRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id, $this->viewerRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->hasRole('Kodim Admin'));
        $this->assertTrue($user->hasRole('Viewer'));
        $this->assertCount(2, $user->roles);
    }

    public function test_kodim_admin_cannot_add_reporter_role_without_office_change(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);
        $user->roles()->attach($this->kodimAdminRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id, $this->reporterRole->id])
            ->call('saveUser')
            ->assertHasErrors(['editRoleIds']);

        $user->refresh();
        $this->assertTrue($user->hasRole('Kodim Admin'));
        $this->assertFalse($user->hasRole('Reporter'));
    }

    public function test_reporter_can_add_viewer_role(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);
        $user->roles()->attach($this->reporterRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->reporterRole->id, $this->viewerRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->hasRole('Reporter'));
        $this->assertTrue($user->hasRole('Viewer'));
    }

    public function test_user_can_remove_additional_roles(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);
        $user->roles()->attach([
            $this->kodimAdminRole->id => ['assigned_by' => $this->admin->id],
            $this->viewerRole->id => ['assigned_by' => $this->admin->id],
        ]);

        $this->assertCount(2, $user->roles);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id]) // Remove Viewer
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->hasRole('Kodim Admin'));
        $this->assertFalse($user->hasRole('Viewer'));
        $this->assertCount(1, $user->roles);
    }

    public function test_multiple_level_restricted_roles_cannot_coexist(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);

        $this->actingAs($this->admin);

        // Try to assign both Kodim Admin and Reporter (different levels)
        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id, $this->reporterRole->id])
            ->call('saveUser')
            ->assertHasErrors(['editRoleIds']);

        $user->refresh();
        $this->assertCount(0, $user->roles);
    }

    // F. Registration Flow

    public function test_user_registers_with_kodim_only_office_id_is_kodim(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', null)
            ->set('nrp', '12345678')
            ->set('phone', '08123456789')
            ->set('kodimId', $this->kodim1->id)
            ->set('officeId', null) // No Koramil selected
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user = User::where('nrp', '12345678')->first();
        $this->assertNotNull($user);
        $this->assertEquals($this->kodim1->id, $user->office_id);
        $this->assertFalse($user->is_approved);
    }

    public function test_user_registers_with_kodim_and_koramil_office_id_is_koramil(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Test User 2')
            ->set('email', null)
            ->set('nrp', '87654321')
            ->set('phone', '08987654321')
            ->set('kodimId', $this->kodim1->id)
            ->set('officeId', $this->koramil1->id) // Koramil selected
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user = User::where('nrp', '87654321')->first();
        $this->assertNotNull($user);
        $this->assertEquals($this->koramil1->id, $user->office_id);
        $this->assertFalse($user->is_approved);
    }

    // G. Edge Cases

    public function test_removing_all_roles_is_allowed(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);
        $user->roles()->attach($this->kodimAdminRole->id, ['assigned_by' => $this->admin->id]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', []) // Remove all roles
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertCount(0, $user->roles);
    }

    public function test_role_assignment_includes_assigned_by_tracking(): void
    {
        $user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);

        $this->actingAs($this->admin);

        Volt::test('admin.users.index')
            ->call('editUser', $user->id)
            ->set('editRoleIds', [$this->kodimAdminRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();
        $pivot = $user->roles()->where('role_id', $this->kodimAdminRole->id)->first()->pivot;
        $this->assertEquals($this->admin->id, $pivot->assigned_by);
        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
    }

    public function test_null_office_level_roles_can_be_assigned_at_any_office(): void
    {
        $userKodim = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->kodim1->id,
        ]);

        $userKoramil = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil1->id,
        ]);

        $this->actingAs($this->admin);

        // Assign Viewer (null office_level_id) to Kodim user
        Volt::test('admin.users.index')
            ->call('editUser', $userKodim->id)
            ->set('editRoleIds', [$this->viewerRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        // Assign Viewer to Koramil user
        Volt::test('admin.users.index')
            ->call('editUser', $userKoramil->id)
            ->set('editRoleIds', [$this->viewerRole->id])
            ->call('saveUser')
            ->assertHasNoErrors();

        $userKodim->refresh();
        $userKoramil->refresh();

        $this->assertTrue($userKodim->hasRole('Viewer'));
        $this->assertTrue($userKoramil->hasRole('Viewer'));
    }
}
