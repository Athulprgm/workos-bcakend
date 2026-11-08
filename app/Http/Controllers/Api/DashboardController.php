<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Http\Resources\WorkReportResource;
use App\Models\Bug;
use App\Models\GithubLink;
use App\Models\GithubWebhookEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkLog;
use App\Models\WorkReport;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggregates for the main dashboard.
 *
 * Every number is computed from the caller's own records; nothing is estimated
 * or mocked. Counting is done in the database rather than by loading rows.
 * Supports workspace-specific scoping and combined workload.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->ensureDefaultWorkspaces();

        $workspaceId = $request->query('workspace_id') ?? $request->header('X-Workspace-Id');
        $activeWorkspace = null;

        if ($workspaceId && $workspaceId !== 'all') {
            $workspaceId = (int) $workspaceId;
            $activeWorkspace = $user->workspaces()->where('id', $workspaceId)->first();
            if (! $activeWorkspace) {
                abort(403, 'Unauthorized access to this workspace.');
            }
        }

        $tasks = fn (): Builder => Task::query()
            ->whereHas('project', fn (Builder $project) => $project->where('user_id', $user->id))
            ->when($activeWorkspace, fn (Builder $q) => $q->where(function (Builder $inner) use ($activeWorkspace) {
                $inner->where('workspace_id', $activeWorkspace->id)
                    ->orWhereHas('project', fn (Builder $p) => $p->where('workspace_id', $activeWorkspace->id));
            }));

        $projects = fn (): Builder => Project::query()
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->when($activeWorkspace, fn (Builder $q) => $q->where('workspace_id', $activeWorkspace->id));

        $unfinished = [TaskStatus::Done->value, TaskStatus::Cancelled->value];

        // Tracked time today & this week
        $workLogs = WorkLog::where('user_id', $user->id)
            ->when($activeWorkspace, fn (Builder $q) => $q->where('workspace_id', $activeWorkspace->id));

        $todayMinutes = (clone $workLogs)->whereDate('date', today())->sum('time_spent_minutes');
        $weekMinutes = (clone $workLogs)->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])->sum('time_spent_minutes');

        $recentReports = WorkReport::where('user_id', $user->id)
            ->when($activeWorkspace, fn (Builder $q) => $q->where('workspace_id', $activeWorkspace->id))
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $bugs = fn (): Builder => Bug::query()
            ->visibleTo($user)
            ->when($activeWorkspace, fn (Builder $q) => $q->where('workspace_id', $activeWorkspace->id));

        $githubRepoNames = $user->githubRepositoryLinks()->pluck('full_name');

        return response()->json([
            'data' => [
                'workspace' => $activeWorkspace ? [
                    'id' => $activeWorkspace->id,
                    'name' => $activeWorkspace->name,
                    'company_type' => $activeWorkspace->company_type,
                    'color' => $activeWorkspace->color,
                ] : [
                    'id' => 'all',
                    'name' => 'All Workspaces (Combined)',
                    'company_type' => 'combined',
                    'color' => '#6366F1',
                ],
                'projects' => [
                    'total' => $projects()->count(),
                    'active' => $projects()->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::InProgress->value])->count(),
                    'completed' => $projects()->where('status', ProjectStatus::Completed->value)->count(),
                ],
                'tasks' => [
                    'total' => $tasks()->count(),
                    'open' => $tasks()->whereNotIn('status', $unfinished)->count(),
                    'done' => $tasks()->where('status', TaskStatus::Done->value)->count(),
                    'blocked' => $tasks()->blocked()->count(),
                    'overdue' => $tasks()->overdue()->count(),
                    'due_today' => $tasks()
                        ->whereNotIn('status', $unfinished)
                        ->whereDate('due_at', today())
                        ->count(),
                    'due_within_seven_days' => $tasks()
                        ->whereNotIn('status', $unfinished)
                        ->whereBetween('due_at', [now(), now()->addDays(7)])
                        ->count(),
                ],
                'time_tracking' => [
                    'today_minutes' => (int) $todayMinutes,
                    'today_formatted' => sprintf('%dh %02dm', intdiv($todayMinutes, 60), $todayMinutes % 60),
                    'week_minutes' => (int) $weekMinutes,
                    'week_formatted' => sprintf('%dh %02dm', intdiv($weekMinutes, 60), $weekMinutes % 60),
                ],
                'priority_tasks' => TaskResource::collection(
                    $tasks()
                        ->whereNotIn('status', $unfinished)
                        ->with(['tags', 'assignee', 'dependencies', 'project'])
                        ->withCount('subtasks')
                        ->orderByRaw(
                            "case priority when 'urgent' then 4 when 'high' then 3 when 'medium' then 2 else 1 end desc"
                        )
                        ->orderByRaw('due_at is null')
                        ->orderBy('due_at')
                        ->orderByDesc('id')
                        ->limit(6)
                        ->get()
                ),
                'recent_tasks' => TaskResource::collection(
                    $tasks()
                        ->with(['tags', 'assignee', 'dependencies', 'project'])
                        ->withCount('subtasks')
                        ->orderByDesc('updated_at')
                        ->limit(6)
                        ->get()
                ),
                'recent_projects' => $projects()
                    ->withCount('tasks')
                    ->orderByDesc('updated_at')
                    ->limit(6)
                    ->get()
                    ->map(fn ($project): array => [
                        'id' => $project->id,
                        'name' => $project->name,
                        'status' => $project->status->value,
                        'priority' => $project->priority ?? 'medium',
                        'progress' => $project->tasks_count > 0 ? (int) round(($project->tasks()->whereIn('status', ['done', 'completed'])->count() / $project->tasks_count) * 100) : 0,
                        'tasks_count' => $project->tasks_count,
                        'due_at' => $project->due_at,
                    ]),
                'recent_reports' => WorkReportResource::collection($recentReports),
                'bugs' => [
                    'total' => $bugs()->count(),
                    'open' => $bugs()->where('status', 'open')->count(),
                    'in_progress' => $bugs()->where('status', 'in_progress')->count(),
                    'fixed' => $bugs()->where('status', 'fixed')->count(),
                    'retesting' => $bugs()->where('status', 'retesting')->count(),
                    'closed' => $bugs()->where('status', 'closed')->count(),
                    'critical' => $bugs()->where('priority', 'critical')->count(),
                    'validated' => $bugs()->where('validated', true)->count(),
                ],
                'github' => [
                    'connected' => (bool) $user->githubAccount,
                    'linked_repositories' => $githubRepoNames->count(),
                    'open_links' => GithubLink::where('user_id', $user->id)->where('state', 'open')->count(),
                    'recent_events' => $githubRepoNames->isEmpty()
                        ? []
                        : GithubWebhookEvent::query()
                            ->whereIn('repository', $githubRepoNames)
                            ->orderByDesc('created_at')
                            ->limit(5)
                            ->get()
                            ->map(fn (GithubWebhookEvent $event): array => [
                                'id' => $event->delivery_id,
                                'event' => $event->event.($event->action ? '.'.$event->action : ''),
                                'repository' => $event->repository,
                                'created_at' => $event->created_at?->toIso8601String(),
                            ])
                            ->values()
                            ->all(),
                ],
            ],
        ]);
    }
}
