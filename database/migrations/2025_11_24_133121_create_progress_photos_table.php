<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('progress_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('root_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('photo_date');
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->integer('file_size')->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->text('caption')->nullable();
            $table->timestamps();

            // Indexes for reporting queries
            $table->index(['project_id', 'photo_date'], 'idx_project_date');
            $table->index(['root_task_id', 'photo_date'], 'idx_root_task_date');
            $table->index('user_id', 'idx_user');
            $table->index('created_at', 'idx_created_at');

            // Unique constraint: 1 photo per root task per day per project
            $table->unique(['project_id', 'root_task_id', 'photo_date'], 'unique_daily_photo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('progress_photos');
    }
};
