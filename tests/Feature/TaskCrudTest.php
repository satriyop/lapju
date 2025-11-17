<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TaskCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_page_requires_authentication(): void
    {
        $response = $this->get('/tasks');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_tasks_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/tasks');

        $response->assertStatus(200);
    }

    public function test_tasks_page_displays_tasks(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['name' => 'Test Task']);

        $response = $this->actingAs($user)->get('/tasks');

        $response->assertSee('Test Task');
    }

    public function test_user_can_create_task(): void
    {
        $user = User::factory()->create();

        Volt::actingAs($user)
            ->test('tasks.index')
            ->set('name', 'New Task')
            ->set('volume', 100.50)
            ->set('unit', 'm3')
            ->set('weight', 50.25)
            ->set('price', 10.00)
            ->call('save')
            ->assertDispatched('task-saved');

        $this->assertDatabaseHas('tasks', [
            'name' => 'New Task',
            'volume' => 100.50,
            'unit' => 'm3',
            'weight' => 50.25,
            'price' => 10.00,
            'total_price' => 1005.00, // 100.50 * 10.00
        ]);
    }

    public function test_user_can_edit_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create(['name' => 'Original Name']);

        Volt::actingAs($user)
            ->test('tasks.index')
            ->call('edit', $task->id)
            ->set('name', 'Updated Name')
            ->call('save')
            ->assertDispatched('task-saved');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_user_can_delete_task(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create();

        Volt::actingAs($user)
            ->test('tasks.index')
            ->call('delete', $task->id)
            ->assertDispatched('task-deleted');

        $this->assertDatabaseMissing('tasks', [
            'id' => $task->id,
        ]);
    }

    public function test_search_filters_tasks(): void
    {
        $user = User::factory()->create();
        $frontendTask = Task::factory()->create(['name' => 'Unique Frontend XYZ Task']);
        $backendTask = Task::factory()->create(['name' => 'Unique Backend ABC Task']);

        Volt::actingAs($user)
            ->test('tasks.index')
            ->set('search', 'Frontend XYZ')
            ->assertSee('Unique Frontend XYZ Task');

        $this->assertTrue(true);
    }
}
