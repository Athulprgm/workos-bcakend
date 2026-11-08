<?php

namespace App\Models;

use App\Enums\BugPriority;
use App\Enums\BugSource;
use App\Enums\BugStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Bug extends Model
{
    protected $fillable = [
        'workspace_id',
        'project_id',
        'number',
        'title',
        'details',
        'priority',
        'status',
        'tester_id',
        'tester_name',
        'created_by',
        'testing_notes',
        'validated',
        'validated_by',
        'validated_at',
        'source',
        'external_key',
        'external_updated_at',
        'sync_hash',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => BugPriority::class,
            'status' => BugStatus::class,
            'source' => BugSource::class,
            'validated' => 'boolean',
            'validated_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Bug $bug): void {
            if ($bug->status === BugStatus::Closed) {
                $bug->closed_at ??= now();
            } else {
                $bug->closed_at = null;
            }

            if ($bug->validated && ! $bug->validated_at) {
                $bug->validated_at = now();
            }

            if (! $bug->validated) {
                $bug->validated_at = null;
                $bug->validated_by = null;
            }

            if (! $bug->workspace_id && $bug->project_id) {
                $bug->workspace_id = $bug->project?->workspace_id;
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tester_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function githubLinks(): MorphMany
    {
        return $this->morphMany(GithubLink::class, 'local');
    }

    public function displayTesterName(): ?string
    {
        return $this->tester_name ?: $this->tester?->name;
    }

    /**
     * Human readable bug identifier, e.g. BUG-0042.
     */
    public function reference(): string
    {
        return 'BUG-'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Scope to the projects a caller is allowed to see.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $projectIds = $user->accessibleProjectIds();

        if ($projectIds === null) {
            return $query->whereHas('project', fn (Builder $project) => $project->where('user_id', $user->id));
        }

        return $query->whereIn('project_id', $projectIds);
    }

    /**
     * Allocate the next sequential bug number for a project.
     */
    public static function nextNumberFor(Project $project): int
    {
        return ((int) static::query()->where('project_id', $project->id)->max('number')) + 1;
    }

    /**
     * Deterministic hash of the syncable fields, used for conflict detection.
     */
    public function syncHash(): string
    {
        return hash('sha256', json_encode([
            'title' => (string) $this->title,
            'details' => (string) ($this->details ?? ''),
            'priority' => $this->priority?->value ?? (string) $this->priority,
            'status' => $this->status?->value ?? (string) $this->status,
            'tester' => (string) ($this->displayTesterName() ?? ''),
            'testing_notes' => (string) ($this->testing_notes ?? ''),
            'validated' => (bool) $this->validated,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Ensure an external key is stable and bounded.
     */
    public static function normaliseExternalKey(?string $key): ?string
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return Str::limit(trim($key), 180, '');
    }
}
