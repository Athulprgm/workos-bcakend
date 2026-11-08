<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkLogResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\TelegramService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WorkLogController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'workspace_id' => ['nullable'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $workspaceId = $filters['workspace_id'] ?? $request->header('X-Workspace-Id');
        if ($workspaceId && $workspaceId !== 'all') {
            $workspaceId = (int) $workspaceId;
            if (! $request->user()->workspaces()->where('id', $workspaceId)->exists()) {
                abort(403, 'Unauthorized access to this workspace.');
            }
        }

        $term = $filters['q'] ?? null;

        $logs = $request->user()->workLogs()
            ->with(['workspace', 'project', 'task', 'user'])
            ->when($workspaceId && $workspaceId !== 'all', fn (Builder $q) => $q->where('workspace_id', $workspaceId))
            ->when($filters['project_id'] ?? null, fn (Builder $q, int $id) => $q->where('project_id', $id))
            ->when($filters['task_id'] ?? null, fn (Builder $q, int $id) => $q->where('task_id', $id))
            ->when($filters['date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('date', $date))
            ->when($filters['start_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('date', '>=', $date))
            ->when($filters['end_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('date', '<=', $date))
            ->when($term, function (Builder $query) use ($term): void {
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('work_description', 'like', "%{$term}%")
                        ->orWhere('problems_encountered', 'like', "%{$term}%")
                        ->orWhere('next_steps', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return WorkLogResource::collection($logs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'work_description' => ['required', 'string', 'max:20000'],
            'date' => ['nullable', 'date'],
            'time_spent_minutes' => ['nullable', 'integer', 'min:0'],
            'problems_encountered' => ['nullable', 'string', 'max:5000'],
            'next_steps' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        // Resolve workspace
        if (empty($data['workspace_id'])) {
            $headerWorkspace = $request->header('X-Workspace-Id');
            if ($headerWorkspace && $headerWorkspace !== 'all' && $user->workspaces()->where('id', (int) $headerWorkspace)->exists()) {
                $data['workspace_id'] = (int) $headerWorkspace;
            } elseif (! empty($data['project_id'])) {
                $proj = Project::find($data['project_id']);
                $data['workspace_id'] = $proj?->workspace_id ?? $user->workspaces()->active()->first()?->id;
            } else {
                $data['workspace_id'] = $user->workspaces()->active()->first()?->id;
            }
        }

        // Verify workspace belongs to user
        if (! $user->workspaces()->where('id', $data['workspace_id'])->exists()) {
            abort(403, 'Unauthorized access to the selected workspace.');
        }

        // Verify project belongs to user and workspace
        if (! empty($data['project_id'])) {
            $project = Project::find($data['project_id']);
            if ($project->user_id !== $user->id) {
                abort(403, 'Unauthorized project.');
            }
            if ($project->workspace_id && $project->workspace_id !== (int) $data['workspace_id']) {
                abort(422, 'The selected project belongs to another workspace.');
            }
        }

        // Verify task belongs to project/user
        if (! empty($data['task_id'])) {
            $task = Task::find($data['task_id']);
            if ($task->project->user_id !== $user->id) {
                abort(403, 'Unauthorized task.');
            }
            if (! empty($data['project_id']) && $task->project_id !== (int) $data['project_id']) {
                abort(422, 'The task does not belong to the selected project.');
            }
        }

        $data['date'] ??= today()->format('Y-m-d');
        $data['time_spent_minutes'] ??= 0;

        // CRITICAL: We do NOT automatically mark task completed merely because a work log was created.
        $workLog = $user->workLogs()->create($data);
        $workLog = $workLog->load(['workspace', 'project', 'task', 'user']);

        $this->notifyWorkLog($workLog, $user);

        return WorkLogResource::make($workLog)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, WorkLog $workLog): WorkLogResource
    {
        $this->authorizeAccess($request, $workLog);

        return new WorkLogResource($workLog->load(['workspace', 'project', 'task', 'user']));
    }

    public function update(Request $request, WorkLog $workLog): WorkLogResource
    {
        $this->authorizeAccess($request, $workLog);

        $data = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'work_description' => ['sometimes', 'required', 'string', 'max:20000'],
            'date' => ['nullable', 'date'],
            'time_spent_minutes' => ['nullable', 'integer', 'min:0'],
            'problems_encountered' => ['nullable', 'string', 'max:5000'],
            'next_steps' => ['nullable', 'string', 'max:5000'],
        ]);

        $workLog->update($data);

        return new WorkLogResource($workLog->load(['workspace', 'project', 'task', 'user']));
    }

    public function destroy(Request $request, WorkLog $workLog): Response
    {
        $this->authorizeAccess($request, $workLog);

        $workLog->delete();

        return response()->noContent();
    }

    public function stats(Request $request): JsonResponse
    {
        $workspaceId = $request->query('workspace_id') ?? $request->header('X-Workspace-Id');
        if ($workspaceId && $workspaceId !== 'all') {
            $workspaceId = (int) $workspaceId;
            if (! $request->user()->workspaces()->where('id', $workspaceId)->exists()) {
                abort(403, 'Unauthorized access to this workspace.');
            }
        }

        $query = $request->user()->workLogs()
            ->when($workspaceId && $workspaceId !== 'all', fn (Builder $q) => $q->where('workspace_id', $workspaceId));

        $todayMinutes = (clone $query)->whereDate('date', today())->sum('time_spent_minutes');
        $weekMinutes = (clone $query)->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])->sum('time_spent_minutes');
        $monthMinutes = (clone $query)->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->sum('time_spent_minutes');

        return response()->json([
            'data' => [
                'today_minutes' => (int) $todayMinutes,
                'week_minutes' => (int) $weekMinutes,
                'month_minutes' => (int) $monthMinutes,
                'today_formatted' => sprintf('%dh %02dm', intdiv($todayMinutes, 60), $todayMinutes % 60),
                'week_formatted' => sprintf('%dh %02dm', intdiv($weekMinutes, 60), $weekMinutes % 60),
                'month_formatted' => sprintf('%dh %02dm', intdiv($monthMinutes, 60), $monthMinutes % 60),
            ],
        ]);
    }

    private function notifyWorkLog(WorkLog $workLog, User $user): void
    {
        $fields = [
            'Log' => Str::limit($workLog->work_description, 180),
            'Project' => $workLog->project?->name,
            'Task' => $workLog->task?->title,
            'Date' => $workLog->date?->format('M d, Y'),
            'Time' => $workLog->time_spent_minutes > 0
                ? sprintf('%dh %02dm', intdiv($workLog->time_spent_minutes, 60), $workLog->time_spent_minutes % 60)
                : null,
            'Problems' => $workLog->problems_encountered ? Str::limit($workLog->problems_encountered, 120) : null,
        ];

        $this->telegram->sendToUser($user, TelegramService::EVENT_WORK_LOG_CREATED, $this->telegram->message('📝 Work log added', $fields), [
            'workspace_id' => $workLog->workspace_id,
            'payload' => ['work_log_id' => $workLog->id],
        ]);
    }

    private function authorizeAccess(Request $request, WorkLog $workLog): void
    {
        if ($workLog->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized access to this work log.');
        }
    }
}
