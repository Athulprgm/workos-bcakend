<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'workspace_id', 'name', 'slug', 'description', 'status', 'priority', 'tech_stack', 'repository_url', 'start_date', 'due_at', 'notes', 'milestones', 'archived_at'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            if (empty($project->slug) && ! empty($project->name)) {
                $user = $project->user ?? ($project->user_id ? User::find($project->user_id) : null);
                if ($user) {
                    $project->slug = static::uniqueSlugFor($user, $project->name);
                } else {
                    $project->slug = Str::slug($project->name);
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'tech_stack' => 'array',
            'milestones' => 'array',
            'start_date' => 'datetime',
            'due_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The workspace this project belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The owner of the project. Ownership drives every authorization decision.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Only the top level tasks, excluding subtasks.
     */
    public function rootTasks(): HasMany
    {
        return $this->hasMany(Task::class)->whereNull('parent_id');
    }

    /**
     * Build a slug that is unique among this user's projects.
     */
    public static function uniqueSlugFor(User $user, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'project';
        }

        $slug = $base;

        for ($suffix = 2; $suffix <= 1000; $suffix++) {
            if (! static::slugExists($user, $slug, $ignoreId)) {
                return $slug;
            }

            $slug = $base.'-'.$suffix;
        }

        // Practically unreachable; guarantees the method always terminates.
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
