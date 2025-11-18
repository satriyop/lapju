<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'parent_id',
        'level_id',
        'name',
        'code',
        'notes',
        '_lft',
        '_rgt',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            '_lft' => 'integer',
            '_rgt' => 'integer',
        ];
    }

    /**
     * Get the parent office.
     *
     * @return BelongsTo<Office, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'parent_id');
    }

    /**
     * Get the child offices.
     *
     * @return HasMany<Office, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Office::class, 'parent_id');
    }

    /**
     * Get the office level.
     *
     * @return BelongsTo<OfficeLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(OfficeLevel::class, 'level_id');
    }

    /**
     * Get all users assigned to this office.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'office_id');
    }

    /**
     * Get all projects assigned to this office.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'office_id');
    }

    /**
     * Get the full hierarchy path (breadcrumb).
     */
    public function getHierarchyPath(): string
    {
        $path = [$this->name];
        $current = $this;

        while ($current->parent_id) {
            $parent = $current->parent;
            if (! $parent) {
                break;
            }
            array_unshift($path, $parent->name);
            $current = $parent;
        }

        return implode(' > ', $path);
    }

    /**
     * Get all descendant offices.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Office>
     */
    public function descendants(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('_lft', '>', $this->_lft)
            ->where('_rgt', '<', $this->_rgt)
            ->orderBy('_lft')
            ->get();
    }

    /**
     * Get all ancestor offices.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Office>
     */
    public function ancestors(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('_lft', '<', $this->_lft)
            ->where('_rgt', '>', $this->_rgt)
            ->orderBy('_lft')
            ->get();
    }
}
