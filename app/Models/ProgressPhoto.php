<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProgressPhoto extends Model
{
    protected $fillable = [
        'project_id',
        'root_task_id',
        'user_id',
        'photo_date',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'width',
        'height',
        'caption',
    ];

    protected function casts(): array
    {
        return [
            'photo_date' => 'date',
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * Get the project that owns the photo.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the root task that owns the photo.
     */
    public function rootTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'root_task_id');
    }

    /**
     * Get the user who uploaded the photo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the full URL for the photo.
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }

    /**
     * Check if the photo can be edited (same day only).
     */
    public function canEdit(): bool
    {
        return $this->created_at->isToday() && $this->user_id === auth()->id();
    }

    /**
     * Delete the photo file from storage.
     */
    public function deleteFile(): bool
    {
        if (Storage::disk('public')->exists($this->file_path)) {
            return Storage::disk('public')->delete($this->file_path);
        }

        return false;
    }
}
