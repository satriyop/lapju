<?php

declare(strict_types=1);

namespace Tests\Feature\Progress;

use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProgressPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::factory()->create([
            'is_approved' => true,
        ]);

        $this->project = Project::factory()->create();
        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);
    }

    public function test_can_upload_valid_jpg_photo(): void
    {
        $file = UploadedFile::fake()->image('progress.jpg', 1920, 1080)->size(2000);

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $file->store('progress-photos', 'public'),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'width' => 1920,
            'height' => 1080,
        ]);

        $this->assertDatabaseHas('progress_photos', [
            'id' => $photo->id,
            'file_name' => 'progress.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('public')->assertExists($photo->file_path);
    }

    public function test_can_upload_valid_png_photo(): void
    {
        $file = UploadedFile::fake()->image('progress.png', 1920, 1080)->size(2000);

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $file->store('progress-photos', 'public'),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'width' => 1920,
            'height' => 1080,
        ]);

        $this->assertDatabaseHas('progress_photos', [
            'id' => $photo->id,
            'mime_type' => 'image/png',
        ]);
    }

    public function test_photo_stores_metadata_correctly(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 1024, 768)->size(1500);

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $file->store('progress-photos', 'public'),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'width' => 1024,
            'height' => 768,
            'caption' => 'Foundation work completed',
        ]);

        $this->assertEquals(1024, $photo->width);
        $this->assertEquals(768, $photo->height);
        $this->assertEquals('Foundation work completed', $photo->caption);
        $this->assertGreaterThan(0, $photo->file_size);
    }

    public function test_unique_daily_photo_constraint_per_project_task_date(): void
    {
        $today = now()->toDateString();

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => $today,
            'file_path' => 'progress-photos/photo1.jpg',
            'file_name' => 'photo1.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        // Attempting to create another photo for same project/task/date should fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => $today,
            'file_path' => 'progress-photos/photo2.jpg',
            'file_name' => 'photo2.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_can_upload_different_photos_for_different_dates(): void
    {
        $yesterday = now()->subDay()->toDateString();
        $today = now()->toDateString();

        $photo1 = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => $yesterday,
            'file_path' => 'progress-photos/yesterday.jpg',
            'file_name' => 'yesterday.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $photo2 = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => $today,
            'file_path' => 'progress-photos/today.jpg',
            'file_name' => 'today.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertDatabaseCount('progress_photos', 2);
    }

    public function test_can_upload_photos_for_different_tasks_same_date(): void
    {
        $task2 = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => null,
            '_lft' => 3,
            '_rgt' => 4,
        ]);

        $today = now()->toDateString();

        $photo1 = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => $today,
            'file_path' => 'progress-photos/task1.jpg',
            'file_name' => 'task1.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $photo2 = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $task2->id,
            'user_id' => $this->user->id,
            'photo_date' => $today,
            'file_path' => 'progress-photos/task2.jpg',
            'file_name' => 'task2.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertDatabaseCount('progress_photos', 2);
    }

    public function test_photo_belongs_to_project(): void
    {
        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
        ]);

        $this->assertInstanceOf(Project::class, $photo->project);
        $this->assertEquals($this->project->id, $photo->project->id);
    }

    public function test_photo_belongs_to_root_task(): void
    {
        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
        ]);

        $this->assertInstanceOf(Task::class, $photo->rootTask);
        $this->assertEquals($this->task->id, $photo->rootTask->id);
    }

    public function test_photo_belongs_to_user(): void
    {
        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertInstanceOf(User::class, $photo->user);
        $this->assertEquals($this->user->id, $photo->user->id);
    }

    public function test_delete_file_removes_photo_from_storage(): void
    {
        $file = UploadedFile::fake()->image('delete-test.jpg');
        $path = $file->store('progress-photos', 'public');

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $path,
            'file_name' => 'delete-test.jpg',
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        Storage::disk('public')->assertExists($path);

        $photo->deleteFile();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_can_edit_returns_true_for_today_and_own_photo(): void
    {
        $this->actingAs($this->user);

        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'created_at' => now(),
        ]);

        $this->assertTrue($photo->canEdit());
    }

    public function test_can_edit_returns_false_for_old_photos(): void
    {
        $this->actingAs($this->user);

        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subDay(),
        ]);

        $this->assertFalse($photo->canEdit());
    }

    public function test_can_edit_returns_false_for_other_users_photos(): void
    {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'created_at' => now(),
        ]);

        $this->assertFalse($photo->canEdit());
    }

    public function test_get_url_attribute_returns_storage_url(): void
    {
        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'file_path' => 'progress-photos/test.jpg',
        ]);

        $url = $photo->url;

        $this->assertStringContainsString('progress-photos/test.jpg', $url);
    }

    public function test_photo_cascades_delete_when_project_deleted(): void
    {
        $photo = ProgressPhoto::factory()->create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
        ]);

        $this->project->delete();

        $this->assertDatabaseMissing('progress_photos', [
            'id' => $photo->id,
        ]);
    }
}
