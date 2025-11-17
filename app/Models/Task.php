<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $fillable = [
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
}
