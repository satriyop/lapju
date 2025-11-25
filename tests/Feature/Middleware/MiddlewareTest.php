<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_unapproved_user_redirected_to_pending_approval_page(): void
    {
        $unapprovedUser = User::factory()->create([
            'is_approved' => false,
        ]);

        $response = $this->actingAs($unapprovedUser)->get(route('projects.index'));

        $response->assertRedirect(route('pending-approval'));
    }

    public function test_approved_user_can_access_protected_routes(): void
    {
        $approvedUser = User::factory()->create([
            'is_approved' => true,
        ]);

        $response = $this->actingAs($approvedUser)->get(route('projects.index'));

        $response->assertStatus(200);
    }

    public function test_admin_user_can_access_admin_routes(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertStatus(200);
    }

    public function test_non_admin_user_cannot_access_admin_routes(): void
    {
        $regularUser = User::factory()->create([
            'is_admin' => false,
            'is_approved' => true,
        ]);

        $response = $this->actingAs($regularUser)->get(route('admin.users.index'));

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get(route('projects.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_cannot_access_admin_routes(): void
    {
        $response = $this->get(route('admin.users.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_approved_status_checked_before_accessing_projects(): void
    {
        $unapprovedUser = User::factory()->create([
            'is_approved' => false,
        ]);

        $response = $this->actingAs($unapprovedUser)->get(route('dashboard'));

        $response->assertRedirect(route('pending-approval'));
    }

    public function test_pending_approval_route_accessible_to_unapproved_users(): void
    {
        $unapprovedUser = User::factory()->create([
            'is_approved' => false,
        ]);

        $response = $this->actingAs($unapprovedUser)->get(route('pending-approval'));

        $response->assertStatus(200);
    }

    public function test_middleware_allows_approved_admin_through(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertStatus(200);
    }

    public function test_unapproved_admin_redirected_to_pending_approval(): void
    {
        $unapprovedAdmin = User::factory()->create([
            'is_admin' => true,
            'is_approved' => false,
        ]);

        $response = $this->actingAs($unapprovedAdmin)->get(route('projects.index'));

        $response->assertRedirect(route('pending-approval'));
    }
}
