<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficeLevel extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'level',
        'name',
        'description',
        'is_default_user_level',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'is_default_user_level' => 'boolean',
        ];
    }

    /**
     * Get all offices at this level.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Office, $this>
     */
    public function offices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Office::class, 'level_id');
    }

    /**
     * Get the default user level.
     */
    public static function getDefaultUserLevel(): ?self
    {
        return static::where('is_default_user_level', true)->first();
    }
}
