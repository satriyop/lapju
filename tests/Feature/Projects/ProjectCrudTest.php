<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Location;
use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\Partner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Office $kodim;

    private Office $koramil;

    private Partner $partner;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an approved admin user
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        // Create office levels
        $kodimLevel = OfficeLevel::factory()->create(['level' => 3, 'name' => 'Kodim']);
        $koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);

        // Create office hierarchy (Kodim -> Koramil)
        $this->kodim = Office::factory()->create([
            'level_id' => $kodimLevel->id,
            'parent_id' => null,
        ]);

        $this->koramil = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => $this->kodim->id,
        ]);

        // Create supporting data
        $this->partner = Partner::factory()->create();
        $this->location = Location::factory()->create();
    }

    public function test_admin_can_create_project_with_all_required_fields(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(6)->format('Y-m-d'))
            ->set('name', 'Custom Project Name')
            ->set('description', 'Building roads and bridges')
            ->set('status', 'planning')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Custom Project Name',
            'description' => 'Building roads and bridges',
            'partner_id' => $this->partner->id,
            'office_id' => $this->koramil->id,
            'location_id' => $this->location->id,
            'status' => 'planning',
        ]);
    }

    public function test_admin_can_create_project_with_minimal_required_fields(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('name', 'Minimal Project')
            ->set('status', 'planning')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'name' => 'Minimal Project',
            'description' => null,
        ]);
    }

    public function test_project_creation_requires_name(): void
    {
        $this->actingAs($this->admin);

        // Don't set locationId to avoid auto-population of name
        Volt::test('projects.index')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('name', '')
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors(['name', 'locationId']); // Both should error
    }

    public function test_project_creation_requires_partner_id(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', null)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors('partnerId');
    }

    public function test_project_creation_requires_koramil_id(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', null)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors('koramilId');
    }

    public function test_project_creation_requires_location_id(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', null)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors('locationId');
    }

    public function test_project_end_date_must_be_after_or_equal_start_date(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->addMonths(6)->format('Y-m-d'))
            ->set('endDate', now()->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors('endDate');
    }

    public function test_partner_id_must_exist_in_partners_table(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', 99999)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors('partnerId');
    }

    public function test_koramil_id_must_exist_in_offices_table(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', 99999)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors('koramilId');
    }

    public function test_location_id_must_exist_in_locations_table(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', 99999)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'planning')
            ->call('save')
            ->assertHasErrors('locationId');
    }

    public function test_admin_can_update_project(): void
    {
        $this->actingAs($this->admin);

        $project = Project::factory()->create([
            'office_id' => $this->koramil->id,
            'name' => 'Original Name',
            'status' => 'planning',
        ]);

        Volt::test('projects.index')
            ->call('edit', $project->id)
            ->set('name', 'Updated Project Name')
            ->set('description', 'Updated description')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => 'Updated Project Name',
            'description' => 'Updated description',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_delete_project(): void
    {
        $this->actingAs($this->admin);

        $project = Project::factory()->create([
            'office_id' => $this->koramil->id,
        ]);

        Volt::test('projects.index')
            ->call('delete', $project->id);

        $this->assertDatabaseMissing('projects', [
            'id' => $project->id,
        ]);
    }

    public function test_admin_can_view_projects_list(): void
    {
        Project::factory()->count(5)->create([
            'office_id' => $this->koramil->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('projects.index'));

        $response->assertStatus(200);
    }

    public function test_unapproved_user_cannot_access_projects(): void
    {
        $unapprovedUser = User::factory()->create([
            'is_approved' => false,
        ]);

        $response = $this->actingAs($unapprovedUser)->get(route('projects.index'));

        $response->assertRedirect(route('pending-approval'));
    }

    public function test_guest_cannot_access_projects(): void
    {
        $response = $this->get(route('projects.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_project_status_must_be_valid_value(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('name', 'Test Project')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('status', 'invalid_status')
            ->call('save')
            ->assertHasErrors('status');
    }

    public function test_created_project_automatically_assigns_current_user_as_reporter(): void
    {
        $this->actingAs($this->admin);

        Volt::test('projects.index')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('name', 'Auto-assigned Project')
            ->set('status', 'planning')
            ->call('save')
            ->assertHasNoErrors();

        $project = Project::where('name', 'Auto-assigned Project')->first();

        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id' => $this->admin->id,
            'role' => 'reporter',
        ]);
    }

    public function test_search_filters_projects_by_name(): void
    {
        $this->actingAs($this->admin);

        Project::factory()->create([
            'name' => 'Infrastructure Development',
            'office_id' => $this->koramil->id,
        ]);
        Project::factory()->create([
            'name' => 'Education Enhancement',
            'office_id' => $this->koramil->id,
        ]);

        Volt::test('projects.index')
            ->set('search', 'Infrastructure')
            ->assertSee('Infrastructure Development')
            ->assertDontSee('Education Enhancement');
    }

    public function test_project_name_is_auto_populated_from_location(): void
    {
        $this->actingAs($this->admin);

        $location = Location::factory()->create([
            'village_name' => 'Test Village',
        ]);

        Volt::test('projects.index')
            ->set('locationId', $location->id)
            ->assertSet('name', 'Koperasi Merah Putih Test Village');
    }

    public function test_name_can_be_very_long(): void
    {
        $this->actingAs($this->admin);

        $longName = str_repeat('a', 255);

        Volt::test('projects.index')
            ->set('partnerId', $this->partner->id)
            ->set('kodimId', $this->kodim->id)
            ->set('koramilId', $this->koramil->id)
            ->set('locationId', $this->location->id)
            ->set('startDate', now()->format('Y-m-d'))
            ->set('endDate', now()->addMonths(3)->format('Y-m-d'))
            ->set('name', $longName)
            ->set('status', 'planning')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('projects', [
            'name' => $longName,
        ]);
    }
}
