<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'template_task_id',
        'name',
        'volume',
        'unit',
        'weight',
        'price',
        'total_price',
        '_lft',
        '_rgt',
        'parent_id',
    ];

    protected function casts(): array
    {
        return [
            'volume' => 'decimal:2',
            'weight' => 'decimal:2',
            'price' => 'decimal:2',
            'total_price' => 'decimal:2',
            '_lft' => 'integer',
            '_rgt' => 'integer',
            'parent_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task) {
            $task->total_price = $task->price * $task->volume;
        });
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function progress(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TaskProgress::class);
    }

    public function project(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function template(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'template_task_id');
    }

    public function progressPhotos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProgressPhoto::class, 'root_task_id');
    }

    /**
     * Check if this task has any descendant leaf tasks with progress entries.
     * Progress is stored on leaf tasks (tasks without children), so we need to
     * check all descendants within this task's nested set boundaries.
     */
    public function hasAnyDescendantProgress(): bool
    {
        return TaskProgress::whereIn('task_id', function ($query) {
            // Get all leaf tasks (tasks that are not parents) within this task's boundaries
            $query->select('id')
                ->from('tasks')
                ->where('project_id', $this->project_id)
                ->where('_lft', '>', $this->_lft)
                ->where('_rgt', '<', $this->_rgt)
                ->whereNotIn('id', function ($subQuery) {
                    $subQuery->select('parent_id')
                        ->from('tasks')
                        ->where('project_id', $this->project_id)
                        ->whereNotNull('parent_id')
                        ->distinct();
                });
        })->exists();
    }
}
