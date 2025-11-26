<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OfficeLevel $level;

    private Office $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->level = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $this->office = Office::factory()->create(['level_id' => $this->level->id]);

        $this->user = User::factory()->create([
            'is_approved' => true,
            'is_admin' => false,
            'office_id' => $this->office->id,
        ]);
    }

    public function test_role_can_be_created_with_permissions(): void
    {
        $role = Role::create([
            'name' => 'Editor',
            'description' => 'Can edit content',
            'permissions' => ['edit_projects', 'delete_projects'],
            'office_level_id' => $this->level->id,
        ]);

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'name' => 'Editor',
        ]);

        $this->assertEquals(['edit_projects', 'delete_projects'], $role->permissions);
    }

    public function test_permissions_are_cast_to_array(): void
    {
        $role = Role::create([
            'name' => 'Viewer',
            'permissions' => ['view_projects'],
        ]);

        $this->assertIsArray($role->permissions);
        $this->assertContains('view_projects', $role->permissions);
    }

    public function test_user_can_be_assigned_role(): void
    {
        $role = Role::factory()->create([
            'name' => 'Reporter',
            'permissions' => ['view_projects'],
        ]);

        $this->user->roles()->attach($role->id);

        $this->assertTrue($this->user->hasRole($role));
        $this->assertTrue($this->user->hasRole('Reporter'));
    }

    public function test_user_can_have_multiple_roles(): void
    {
        $role1 = Role::factory()->create(['name' => 'Reporter']);
        $role2 = Role::factory()->create(['name' => 'Editor']);

        $this->user->roles()->attach([$role1->id, $role2->id]);

        $this->assertTrue($this->user->hasRole('Reporter'));
        $this->assertTrue($this->user->hasRole('Editor'));
        $this->assertCount(2, $this->user->roles);
    }

    public function test_user_has_permission_from_role(): void
    {
        $role = Role::factory()->create([
            'name' => 'Editor',
            'permissions' => ['edit_projects', 'delete_projects'],
        ]);

        $this->user->roles()->attach($role->id);

        $this->assertTrue($this->user->hasPermission('edit_projects'));
        $this->assertTrue($this->user->hasPermission('delete_projects'));
    }

    public function test_user_without_role_has_no_permissions(): void
    {
        $this->assertFalse($this->user->hasPermission('edit_projects'));
        $this->assertFalse($this->user->hasPermission('delete_projects'));
        $this->assertFalse($this->user->hasPermission('manage_users'));
    }

    public function test_wildcard_permission_grants_all_permissions(): void
    {
        $role = Role::factory()->create([
            'name' => 'SuperAdmin',
            'permissions' => ['*'],
        ]);

        $this->user->roles()->attach($role->id);

        $this->assertTrue($this->user->hasPermission('edit_projects'));
        $this->assertTrue($this->user->hasPermission('delete_projects'));
        $this->assertTrue($this->user->hasPermission('manage_users'));
        $this->assertTrue($this->user->hasPermission('any_permission'));
    }

    public function test_admin_user_always_has_all_permissions(): void
    {
        $adminUser = User::factory()->create([
            'is_approved' => true,
            'is_admin' => true,
        ]);

        // Admin doesn't need any roles
        $this->assertTrue($adminUser->hasPermission('edit_projects'));
        $this->assertTrue($adminUser->hasPermission('delete_projects'));
        $this->assertTrue($adminUser->hasPermission('manage_users'));
        $this->assertTrue($adminUser->hasPermission('any_permission'));
    }

    public function test_user_with_multiple_roles_accumulates_permissions(): void
    {
        $role1 = Role::factory()->create([
            'name' => 'Viewer',
            'permissions' => ['view_projects'],
        ]);

        $role2 = Role::factory()->create([
            'name' => 'Editor',
            'permissions' => ['edit_projects', 'delete_projects'],
        ]);

        $this->user->roles()->attach([$role1->id, $role2->id]);

        // Should have permissions from both roles
        $this->assertTrue($this->user->hasPermission('view_projects'));
        $this->assertTrue($this->user->hasPermission('edit_projects'));
        $this->assertTrue($this->user->hasPermission('delete_projects'));
    }

    public function test_role_assignment_includes_assigned_by(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $role = Role::factory()->create(['name' => 'Reporter']);

        $this->user->roles()->attach($role->id, [
            'assigned_by' => $admin->id,
        ]);

        $pivot = $this->user->roles()->where('role_id', $role->id)->first()->pivot;

        $this->assertEquals($admin->id, $pivot->assigned_by);
    }

    public function test_role_assignment_has_timestamps(): void
    {
        $role = Role::factory()->create(['name' => 'Reporter']);

        $this->user->roles()->attach($role->id);

        $pivot = $this->user->roles()->where('role_id', $role->id)->first()->pivot;

        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
    }

    public function test_role_can_be_removed_from_user(): void
    {
        $role = Role::factory()->create(['name' => 'Reporter']);

        $this->user->roles()->attach($role->id);
        $this->assertTrue($this->user->hasRole('Reporter'));

        $this->user->roles()->detach($role->id);
        $this->user->refresh();

        $this->assertFalse($this->user->hasRole('Reporter'));
    }

    public function test_system_role_flag_is_boolean(): void
    {
        $systemRole = Role::factory()->system()->create([
            'name' => 'System Reporter',
        ]);

        $customRole = Role::factory()->create([
            'name' => 'Custom Role',
        ]);

        $this->assertTrue($systemRole->is_system);
        $this->assertFalse($customRole->is_system);
    }

    public function test_role_can_be_scoped_to_office_level(): void
    {
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);

        $role = Role::factory()->create([
            'name' => 'Kodim Manager',
            'office_level_id' => $kodimLevel->id,
        ]);

        $this->assertEquals($kodimLevel->id, $role->office_level_id);
    }

    public function test_role_can_have_null_office_level(): void
    {
        $role = Role::factory()->create([
            'name' => 'Global Role',
            'office_level_id' => null,
        ]);

        $this->assertNull($role->office_level_id);
    }

    public function test_role_description_is_optional(): void
    {
        $role = Role::factory()->create([
            'name' => 'Simple Role',
            'description' => null,
        ]);

        $this->assertNull($role->description);
    }

    public function test_role_can_have_empty_permissions_array(): void
    {
        $role = Role::factory()->create([
            'name' => 'No Permissions Role',
            'permissions' => [],
        ]);

        $this->user->roles()->attach($role->id);

        $this->assertFalse($this->user->hasPermission('view_projects'));
        $this->assertFalse($this->user->hasPermission('edit_projects'));
    }

    public function test_has_role_method_accepts_role_object(): void
    {
        $role = Role::factory()->create(['name' => 'Reporter']);

        $this->user->roles()->attach($role->id);

        $this->assertTrue($this->user->hasRole($role));
    }

    public function test_has_role_method_accepts_role_name_string(): void
    {
        $role = Role::factory()->create(['name' => 'Reporter']);

        $this->user->roles()->attach($role->id);

        $this->assertTrue($this->user->hasRole('Reporter'));
    }

    public function test_role_can_be_updated(): void
    {
        $role = Role::factory()->create([
            'name' => 'Old Name',
            'permissions' => ['view_projects'],
        ]);

        $role->update([
            'name' => 'New Name',
            'permissions' => ['view_projects', 'edit_projects'],
        ]);

        $this->assertEquals('New Name', $role->fresh()->name);
        $this->assertContains('edit_projects', $role->fresh()->permissions);
    }

    public function test_deleting_role_removes_user_assignments(): void
    {
        $role = Role::factory()->create(['name' => 'Temporary Role']);

        $this->user->roles()->attach($role->id);
        $this->assertTrue($this->user->hasRole('Temporary Role'));

        $roleId = $role->id;
        $role->delete();

        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
        $this->assertDatabaseMissing('role_user', [
            'role_id' => $roleId,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_user_can_check_specific_permission_not_granted(): void
    {
        $role = Role::factory()->create([
            'name' => 'Limited Role',
            'permissions' => ['view_projects'],
        ]);

        $this->user->roles()->attach($role->id);

        $this->assertTrue($this->user->hasPermission('view_projects'));
        $this->assertFalse($this->user->hasPermission('edit_projects'));
        $this->assertFalse($this->user->hasPermission('delete_projects'));
    }

    public function test_permissions_persist_after_user_refresh(): void
    {
        $role = Role::factory()->create([
            'name' => 'Editor',
            'permissions' => ['edit_projects'],
        ]);

        $this->user->roles()->attach($role->id);
        $this->user->refresh();

        $this->assertTrue($this->user->hasPermission('edit_projects'));
    }

    public function test_role_belongs_to_many_users(): void
    {
        $role = Role::factory()->create(['name' => 'Reporter']);

        $user1 = User::factory()->create(['is_approved' => true]);
        $user2 = User::factory()->create(['is_approved' => true]);

        $user1->roles()->attach($role->id);
        $user2->roles()->attach($role->id);

        $this->assertCount(2, $role->users);
        $this->assertTrue($role->users->contains($user1));
        $this->assertTrue($role->users->contains($user2));
    }

    public function test_deleting_user_removes_role_assignments(): void
    {
        $role = Role::factory()->create(['name' => 'Reporter']);

        $this->user->roles()->attach($role->id);

        $userId = $this->user->id;
        $this->user->delete();

        $this->assertDatabaseMissing('role_user', [
            'user_id' => $userId,
            'role_id' => $role->id,
        ]);
    }
}
