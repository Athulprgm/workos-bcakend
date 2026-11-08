<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'company_type', 'color', 'settings', 'archived_at'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class);
    }

    public function workReports(): HasMany
    {
        return $this->hasMany(WorkReport::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public static function uniqueSlugFor(User $user, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'workspace';
        }

        $slug = $base;

        for ($suffix = 2; $suffix <= 1000; $suffix++) {
            if (! static::slugExists($user, $slug, $ignoreId)) {
                return $slug;
            }

            $slug = $base.'-'.$suffix;
        }

        return $base.'-'.Str::random(8);
    }

    private static function slugExists(User $user, string $slug, ?int $ignoreId): bool
    {
        $query = static::query()
            ->where('user_id', $user->id)
            ->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}
