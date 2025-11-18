<?php

namespace Tests\Feature\Auth;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        // Need to seed office levels for the page to render properly
        OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);

        $response = $this->get(route('register'));

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        // Create Kodim level (level 3)
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $kodim = Office::factory()->create([
            'level_id' => $kodimLevel->id,
            'name' => 'Kodim 0735/Surakarta',
        ]);

        // Create Koramil level (level 4) - child of Kodim
        $koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $koramil = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $kodim->id,
            'name' => 'Koramil 01/Laweyan',
        ]);

        Volt::test('auth.register')
            ->set('name', 'John Doe')
            ->set('email', 'test@example.com')
            ->set('nrp', '12345678')
            ->set('phone', '08123456789')
            ->set('kodimId', $kodim->id)
            ->set('officeId', $koramil->id)
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        // New users are created but NOT approved yet (need admin approval)
        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->is_approved);
        $this->assertEquals($koramil->id, $user->office_id);
        $this->assertEquals('12345678', $user->nrp);
        $this->assertEquals('08123456789', $user->phone);
    }

    public function test_registered_user_has_pending_approval_status(): void
    {
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $kodim = Office::factory()->create(['level_id' => $kodimLevel->id]);

        $koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $koramil = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $kodim->id,
        ]);

        Volt::test('auth.register')
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@example.com')
            ->set('nrp', '87654321')
            ->set('phone', '081234567890')
            ->set('kodimId', $kodim->id)
            ->set('officeId', $koramil->id)
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $user = User::where('email', 'jane@example.com')->first();

        $this->assertFalse($user->is_approved);
        $this->assertNull($user->approved_at);
        $this->assertNull($user->approved_by);
    }

    public function test_cascading_kodim_koramil_selection(): void
    {
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $kodim1 = Office::factory()->create([
            'level_id' => $kodimLevel->id,
            'name' => 'Kodim A',
        ]);
        $kodim2 = Office::factory()->create([
            'level_id' => $kodimLevel->id,
            'name' => 'Kodim B',
        ]);

        $koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $koramil1 = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $kodim1->id,
            'name' => 'Koramil A1',
        ]);
        $koramil2 = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $kodim2->id,
            'name' => 'Koramil B1',
        ]);

        // Test cascading selection - when kodim changes, office should reset
        Volt::test('auth.register')
            ->set('kodimId', $kodim1->id)
            ->set('officeId', $koramil1->id)
            ->assertSet('kodimId', $kodim1->id)
            ->assertSet('officeId', $koramil1->id)
            // Change kodim should reset officeId
            ->set('kodimId', $kodim2->id)
            ->assertSet('officeId', null);
    }

    public function test_phone_number_is_required(): void
    {
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $kodim = Office::factory()->create(['level_id' => $kodimLevel->id]);

        $koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $koramil = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $kodim->id,
        ]);

        Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('nrp', '12345678')
            ->set('phone', '') // Empty phone
            ->set('kodimId', $kodim->id)
            ->set('officeId', $koramil->id)
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasErrors(['phone' => 'required']);
    }
}
