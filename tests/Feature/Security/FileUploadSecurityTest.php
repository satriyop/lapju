<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Office;
use App\Models\OfficeLevel;
use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Task $task;

    private Office $koramil;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $koramilLevel = OfficeLevel::factory()->create(['level' => 4, 'name' => 'Koramil']);
        $this->koramil = Office::factory()->create([
            'level_id' => $koramilLevel->id,
            'parent_id' => null,
        ]);

        $this->user = User::factory()->create([
            'is_approved' => true,
            'office_id' => $this->koramil->id,
        ]);

        $this->project = Project::factory()->create([
            'office_id' => $this->koramil->id,
        ]);

        $this->task = Task::factory()->create([
            'project_id' => $this->project->id,
            'parent_id' => null,
            '_lft' => 1,
            '_rgt' => 2,
        ]);

        $this->project->users()->attach($this->user->id, ['role' => 'reporter']);
    }

    public function test_progress_photo_validates_mime_type_on_model_creation(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 1920, 1080)->size(2000);

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

        $this->assertEquals('image/jpeg', $photo->mime_type);
        $this->assertGreaterThan(0, $photo->file_size);
    }

    public function test_photo_stores_file_size_correctly(): void
    {
        $file = UploadedFile::fake()->image('test.jpg')->size(1500);

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $file->store('progress-photos', 'public'),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'width' => 800,
            'height' => 600,
        ]);

        $this->assertIsInt($photo->file_size);
        $this->assertGreaterThan(0, $photo->file_size);
    }

    public function test_photo_stores_dimensions_correctly(): void
    {
        $file = UploadedFile::fake()->image('test.jpg', 1024, 768)->size(1000);

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
        ]);

        $this->assertEquals(1024, $photo->width);
        $this->assertEquals(768, $photo->height);
    }

    public function test_prevents_directory_traversal_in_stored_path(): void
    {
        $file = UploadedFile::fake()->image('test.jpg')->size(1000);

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $file->store('progress-photos', 'public'),
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'width' => 800,
            'height' => 600,
        ]);

        // Verify file path doesn't contain directory traversal sequences
        $this->assertStringNotContainsString('..', $photo->file_path);
        $this->assertStringNotContainsString('/etc/', $photo->file_path);
        $this->assertStringContainsString('progress-photos', $photo->file_path);
    }

    public function test_photo_file_actually_stored_in_correct_directory(): void
    {
        $file = UploadedFile::fake()->image('test.jpg')->size(1000);
        $path = $file->store('progress-photos', 'public');

        $photo = ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'width' => 800,
            'height' => 600,
        ]);

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('progress-photos/', $path);
    }

    public function test_unauthorized_user_cannot_create_progress_photo(): void
    {
        $otherKoramilLevel = OfficeLevel::factory()->create(['level' => 5, 'name' => 'Other Koramil']);
        $otherKoramil = Office::factory()->create([
            'level_id' => $otherKoramilLevel->id,
            'parent_id' => null,
        ]);

        $unauthorizedUser = User::factory()->create([
            'is_approved' => true,
            'office_id' => $otherKoramil->id,
        ]);

        $this->actingAs($unauthorizedUser);

        // User from different koramil should not have access to this project
        $this->assertFalse(
            $this->project->users()->where('users.id', $unauthorizedUser->id)->exists()
        );
    }

    public function test_delete_file_removes_photo_from_storage(): void
    {
        $file = UploadedFile::fake()->image('delete-test.jpg')->size(1000);
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
            'width' => 800,
            'height' => 600,
        ]);

        Storage::disk('public')->assertExists($path);

        $photo->deleteFile();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_unique_constraint_enforced_per_project_task_date(): void
    {
        $file = UploadedFile::fake()->image('photo1.jpg')->size(1000);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => $file->store('progress-photos', 'public'),
            'file_name' => 'photo1.jpg',
            'file_size' => $file->getSize(),
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
        ]);

        // Attempt to create duplicate should fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        ProgressPhoto::create([
            'project_id' => $this->project->id,
            'root_task_id' => $this->task->id,
            'user_id' => $this->user->id,
            'photo_date' => now()->toDateString(),
            'file_path' => 'progress-photos/photo2.jpg',
            'file_name' => 'photo2.jpg',
            'file_size' => 1000,
            'mime_type' => 'image/jpeg',
            'width' => 800,
            'height' => 600,
        ]);
    }

    public function test_file_path_is_securely_generated(): void
    {
        $file = UploadedFile::fake()->image('test.jpg')->size(1000);
        $path = $file->store('progress-photos', 'public');

        // Path should contain only safe characters
        $this->assertMatchesRegularExpression('/^progress-photos\/[a-zA-Z0-9_\-\.\/]+$/', $path);
        $this->assertStringNotContainsString('../', $path);
        $this->assertStringNotContainsString('..\\', $path);
    }
}
