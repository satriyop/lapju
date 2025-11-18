<?php

namespace Tests\Feature\Admin;

use App\Models\Office;
use App\Models\OfficeLevel;
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
    }

    public function test_admin_can_access_user_management_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertStatus(200);
    }

    public function test_admin_can_create_user_with_pre_approval(): void
    {
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->assertSet('showCreateModal', true)
            ->set('createName', 'New User')
            ->set('createEmail', 'newuser@example.com')
            ->set('createNrp', 'NRP12345')
            ->set('createPhone', '08123456789')
            ->set('createKodimId', $this->kodim->id)
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
    }

    public function test_admin_can_create_user_with_admin_privileges(): void
    {
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Admin User')
            ->set('createEmail', 'admin@example.com')
            ->set('createNrp', 'ADMIN123')
            ->set('createPhone', '08987654321')
            ->set('createKodimId', $this->kodim->id)
            ->set('createOfficeId', $this->koramil->id)
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
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Pending User')
            ->set('createEmail', 'pending@example.com')
            ->set('createNrp', 'PENDING123')
            ->set('createPhone', '08111222333')
            ->set('createKodimId', $this->kodim->id)
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
    }

    public function test_cascading_kodim_koramil_selection_in_create_modal(): void
    {
        // Create another kodim and koramil
        $kodim2 = Office::factory()->create(['level_id' => $this->kodimLevel->id]);
        $koramil2 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim2->id,
        ]);

        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createKodimId', $this->kodim->id)
            ->set('createOfficeId', $this->koramil->id)
            ->assertSet('createOfficeId', $this->koramil->id)
            // Change kodim should reset office
            ->set('createKodimId', $kodim2->id)
            ->assertSet('createOfficeId', null);
    }

    public function test_create_user_validates_required_fields(): void
    {
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
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
                'createKodimId' => 'required',
                'createOfficeId' => 'required',
                'createPassword' => 'required',
            ]);
    }

    public function test_create_user_validates_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'taken@example.com')
            ->set('createNrp', 'NRP999')
            ->set('createPhone', '08123456789')
            ->set('createKodimId', $this->kodim->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createEmail' => 'unique']);
    }

    public function test_create_user_validates_unique_nrp(): void
    {
        User::factory()->create(['nrp' => 'TAKEN123']);

        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'unique@example.com')
            ->set('createNrp', 'TAKEN123')
            ->set('createPhone', '08123456789')
            ->set('createKodimId', $this->kodim->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createNrp' => 'unique']);
    }

    public function test_create_user_validates_password_confirmation(): void
    {
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'test@example.com')
            ->set('createNrp', 'NRP888')
            ->set('createPhone', '08123456789')
            ->set('createKodimId', $this->kodim->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'differentpassword')
            ->call('createUser')
            ->assertHasErrors(['createPassword' => 'same']);
    }

    public function test_create_user_validates_phone_format(): void
    {
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'test@example.com')
            ->set('createNrp', 'NRP777')
            ->set('createPhone', 'invalid-phone!')
            ->set('createKodimId', $this->kodim->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createPhone' => 'regex']);
    }

    public function test_create_user_validates_office_belongs_to_kodim(): void
    {
        // Create another kodim and koramil
        $kodim2 = Office::factory()->create(['level_id' => $this->kodimLevel->id]);
        $koramil2 = Office::factory()->create([
            'level_id' => $this->koramilLevel->id,
            'parent_id' => $kodim2->id,
        ]);

        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Test User')
            ->set('createEmail', 'test@example.com')
            ->set('createNrp', 'NRP666')
            ->set('createPhone', '08123456789')
            ->set('createKodimId', $this->kodim->id)
            // Try to assign koramil from different kodim
            ->set('createOfficeId', $koramil2->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasErrors(['createOfficeId']);
    }

    public function test_open_create_modal_resets_form(): void
    {
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->set('createName', 'Old Name')
            ->set('createEmail', 'old@example.com')
            ->call('openCreateModal')
            ->assertSet('createName', '')
            ->assertSet('createEmail', '')
            ->assertSet('createNrp', '')
            ->assertSet('createPhone', '')
            ->assertSet('createKodimId', null)
            ->assertSet('createOfficeId', null)
            ->assertSet('createPassword', '')
            ->assertSet('createPasswordConfirmation', '')
            ->assertSet('createIsAdmin', false)
            ->assertSet('createIsApproved', true);
    }

    public function test_created_user_password_is_hashed(): void
    {
        Volt::actingAs($this->admin)
            ->test('admin.users.index')
            ->call('openCreateModal')
            ->set('createName', 'Password Test')
            ->set('createEmail', 'passtest@example.com')
            ->set('createNrp', 'NRP555')
            ->set('createPhone', '08123456789')
            ->set('createKodimId', $this->kodim->id)
            ->set('createOfficeId', $this->koramil->id)
            ->set('createPassword', 'password123')
            ->set('createPasswordConfirmation', 'password123')
            ->call('createUser')
            ->assertHasNoErrors();

        $user = User::where('email', 'passtest@example.com')->first();
        $this->assertNotEquals('password123', $user->password);
        $this->assertTrue(password_verify('password123', $user->password));
    }
}
