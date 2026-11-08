<?php

namespace App\Models;

use App\Enums\ReportVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'workspace_id',
    'type',
    'period_start',
    'period_end',
    'title',
    'company_name',
    'project_name',
    'work_description',
    'status',
    'visibility',
    'shared_with_gm',
    'gm_user_id',
    'shared_at',
    'projects_worked',
    'completed_work',
    'work_in_progress',
    'pending_tasks',
    'issues_blockers',
    'time_spent_minutes',
    'next_steps',
    'raw_content',
    'share_token',
])]
class WorkReport extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (WorkReport $report): void {
            if (empty($report->share_token)) {
                $report->share_token = \Illuminate\Support\Str::random(32);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'visibility' => ReportVisibility::class,
            'shared_with_gm' => 'boolean',
            'shared_at' => 'datetime',
            'projects_worked' => 'array',
            'completed_work' => 'array',
            'work_in_progress' => 'array',
            'pending_tasks' => 'array',
            'issues_blockers' => 'array',
            'next_steps' => 'array',
            'time_spent_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The General Manager this report is explicitly shared with.
     */
    public function generalManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_user_id');
    }

    public function isPublic(): bool
    {
        return $this->visibility === ReportVisibility::Public;
    }

    /**
     * Build clean structured tabular rows for daily and period report deliverables.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tableData(): array
    {
        $rows = [];
        $i = 1;

        foreach ($this->completed_work ?? [] as $item) {
            $rows[] = [
                'index' => $i++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'Completed',
                'status_tone' => 'success',
                'time_spent' => $item['time_spent'] ?? '—',
                'notes' => $item['completed_at'] ?? $item['date'] ?? 'Delivered',
            ];
        }

        foreach ($this->work_in_progress ?? [] as $item) {
            $rows[] = [
                'index' => $i++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'In Progress',
                'status_tone' => 'warning',
                'time_spent' => '—',
                'notes' => isset($item['due_at']) && $item['due_at'] ? "Due: {$item['due_at']}" : ($item['priority'] ?? 'In progress'),
            ];
        }

        foreach ($this->pending_tasks ?? [] as $item) {
            $rows[] = [
                'index' => $i++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'Pending',
                'status_tone' => 'neutral',
                'time_spent' => '—',
                'notes' => $item['priority'] ?? 'Backlog',
            ];
        }

        foreach ($this->issues_blockers ?? [] as $item) {
            $rows[] = [
                'index' => $i++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'Blocked',
                'status_tone' => 'danger',
                'time_spent' => '—',
                'notes' => $item['reason'] ?? 'Blocked',
            ];
        }

        return $rows;
    }

    public function isSharedWith(User $user): bool
    {
        return $this->shared_with_gm && (int) $this->gm_user_id === (int) $user->id;
    }

    /**
     * Reports a user is allowed to read: owned, public, or shared with them as GM.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('user_id', $user->id)
                ->orWhere(function (Builder $public): void {
                    $public->where('visibility', ReportVisibility::Public->value);
                })
                ->orWhere(function (Builder $shared) use ($user): void {
                    $shared->where('shared_with_gm', true)->where('gm_user_id', $user->id);
                });
        });
    }

    public function getProjectNameAttribute(?string $value): string
    {
        if (! empty($value)) {
            return $value;
        }

        $projectsWorked = $this->projects_worked ?? [];
        if (! empty($projectsWorked[0]['name'])) {
            return $projectsWorked[0]['name'];
        }

        $completed = $this->completed_work ?? [];
        if (! empty($completed[0]['project'])) {
            return $completed[0]['project'];
        }

        return $this->company_name ?? 'General';
    }

    public function getWorkDescriptionAttribute(?string $value): string
    {
        if (! empty($value)) {
            return $value;
        }

        $completed = $this->completed_work ?? [];
        if (! empty($completed[0]['description'])) {
            return $completed[0]['description'];
        }
        if (! empty($completed[0]['title'])) {
            return $completed[0]['title'];
        }

        $wip = $this->work_in_progress ?? [];
        if (! empty($wip[0]['title'])) {
            return $wip[0]['title'];
        }

        $pending = $this->pending_tasks ?? [];
        if (! empty($pending[0]['title'])) {
            return $pending[0]['title'];
        }

        return $this->title ?? '';
    }

    public function getStatusAttribute(?string $value): string
    {
        if (! empty($value)) {
            return $value;
        }

        if (! empty($this->work_in_progress) && empty($this->completed_work)) {
            return 'In Progress';
        }

        if (! empty($this->pending_tasks) && empty($this->completed_work) && empty($this->work_in_progress)) {
            return 'Pending';
        }

        return 'Completed';
    }
}
