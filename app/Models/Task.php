<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'workspace_id',
    'project_id',
    'title',
    'description',
    'status',
    'priority',
    'due_at',
    'parent_id',
    'user_id',
    'estimated_duration_minutes',
    'actual_time_spent_minutes',
    'completion_notes',
    'recurring',
    'activity_history',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_duration_minutes' => 'integer',
            'actual_time_spent_minutes' => 'integer',
            'activity_history' => 'array',
        ];
    }

    /**
     * Keep completed_at consistent with status so callers never have to
     * remember to set it themselves.
     */
    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            if ($task->status?->isClosed()) {
                $task->completed_at ??= now();
            } else {
                $task->completed_at = null;
            }

            // If task has no workspace_id but project has one, sync it
            if (! $task->workspace_id && $task->project_id) {
                $task->workspace_id = $task->project?->workspace_id;
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The user this task is assigned to.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_task');
    }

    public function githubLinks(): MorphMany
    {
        return $this->morphMany(GithubLink::class, 'local');
    }

    /**
     * Tasks this task is waiting on.
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id')
            ->withTimestamps();
    }

    /**
     * Tasks that are waiting on this one.
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id')
            ->withTimestamps();
    }

    /**
     * Tasks with at least one unfinished dependency.
     */
    public function scopeBlocked(Builder $query): Builder
    {
        return $query->whereHas('dependencies', function (Builder $dependency): void {
            $dependency->whereNotIn('status', self::finishedStatuses());
        });
    }

    /**
     * Tasks with no unfinished dependency.
     */
    public function scopeUnblocked(Builder $query): Builder
    {
        return $query->whereDoesntHave('dependencies', function (Builder $dependency): void {
            $dependency->whereNotIn('status', self::finishedStatuses());
        });
    }

    /**
     * Unfinished tasks whose due date has already passed.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', self::finishedStatuses());
    }

    /**
     * @return array<int, string>
     */
    private static function finishedStatuses(): array
    {
        return [TaskStatus::Done->value, TaskStatus::Cancelled->value];
    }

    /**
     * Whether this task sits above $other in the subtask hierarchy.
     */
    public function isAncestorOf(Task $other): bool
    {
        $node = $other;

        // Bounded walk so a pre-existing cycle can never hang the request.
        for ($depth = 0; $depth < 100 && $node->parent_id !== null; $depth++) {
            if ((int) $node->parent_id === (int) $this->id) {
                return true;
            }

            $node = static::query()->find($node->parent_id);

            if (! $node instanceof Task) {
                break;
            }
        }

        return false;
    }

    /**
     * Whether this task has any unfinished dependency. Requires `dependencies`
     * to be loaded to avoid an extra query per task.
     */
    public function isBlocked(): bool
    {
        return $this->dependencies->contains(
            fn (Task $dependency): bool => ! $dependency->status->isClosed()
        );
    }
}
