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

class ProgressPhotoValidationTest extends TestCase
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

    public function test_photo_requires_project_id(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => null,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_photo_requires_root_task_id(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => null,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_photo_requires_user_id(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => null,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_photo_requires_photo_date(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => null,
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_photo_requires_file_path(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => null,
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);
    }

    public function test_photo_file_size_must_be_positive(): void
    {
        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 2048,
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertGreaterThan(0, $photo->file_size);
    }

    public function test_photo_width_and_height_are_stored(): void
    {
        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
            'width' => 1920,
            'height' => 1080,
        ]);

        $this->assertEquals(1920, $photo->width);
        $this->assertEquals(1080, $photo->height);
    }

    public function test_photo_caption_can_be_null(): void
    {
        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
            'caption' => null,
        ]);

        $this->assertNull($photo->caption);
    }

    public function test_photo_caption_can_be_updated(): void
    {
        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
            'caption' => 'Initial caption',
        ]);

        $photo->update(['caption' => 'Updated caption']);

        $this->assertEquals('Updated caption', $photo->fresh()->caption);
    }

    public function test_multiple_photos_can_exist_for_same_project(): void
    {
        $task2 = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => null,
            '_lft' => 3,
            '_rgt' => 4,
        ]);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'photo1.jpg',
            'file_name' => 'photo1.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $task2->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'photo2.jpg',
            'file_name' => 'photo2.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $count = ProgressPhoto::where('project_id', $this->project->id)->count();
        $this->assertEquals(2, $count);
    }

    public function test_photo_date_is_cast_to_date(): void
    {
        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => '2025-01-15',
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertInstanceOf(\Carbon\Carbon::class, $photo->photo_date);
        $this->assertEquals('2025-01-15', $photo->photo_date->toDateString());
    }

    public function test_photo_has_timestamps(): void
    {
        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'test.jpg',
            'file_name' => 'test.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertNotNull($photo->created_at);
        $this->assertNotNull($photo->updated_at);
    }

    public function test_deleting_photo_record_does_not_auto_delete_file(): void
    {
        $file = UploadedFile::fake()->image('test.jpg');
        $path = $file->store('progress-photos', 'public');

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $path,
            'file_name' => 'test.jpg',
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        Storage::disk('public')->assertExists($path);

        // Delete the record (without calling deleteFile())
        $photo->delete();

        // File should still exist in storage
        Storage::disk('public')->assertExists($path);
    }

    public function test_photo_can_be_queried_by_date_range(): void
    {
        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->subDays(5)->toDateString(),
            'file_path' => 'old.jpg',
            'file_name' => 'old.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'recent.jpg',
            'file_name' => 'recent.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $recentPhotos = ProgressPhoto::where('photo_date', '>=', now()->subDays(3))->get();

        $this->assertCount(1, $recentPhotos);
        $this->assertEquals('recent.jpg', $recentPhotos->first()->file_name);
    }

    public function test_photo_can_be_queried_by_user(): void
    {
        $user2 = User::factory()->create(['is_approved' => true]);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'user1.jpg',
            'file_name' => 'user1.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $user2->id,
            'photo_date' => now()->subDay()->toDateString(),
            'file_path' => 'user2.jpg',
            'file_name' => 'user2.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $user1Photos = ProgressPhoto::where('user_id', $this->user->id)->get();

        $this->assertCount(1, $user1Photos);
        $this->assertEquals('user1.jpg', $user1Photos->first()->file_name);
    }

    public function test_project_can_have_photos_from_multiple_users(): void
    {
        $user2 = User::factory()->create(['is_approved' => true]);

        $task2 = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => null,
            '_lft' => 3,
            '_rgt' => 4,
        ]);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'user1.jpg',
            'file_name' => 'user1.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $task2->id,
            'user_id' => $user2->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'user2.jpg',
            'file_name' => 'user2.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
        ]);

        $projectPhotos = ProgressPhoto::where('project_id', $this->project->id)->get();

        $this->assertCount(2, $projectPhotos);
        $uniqueUsers = $projectPhotos->pluck('user_id')->unique();
        $this->assertCount(2, $uniqueUsers);
    }
}
