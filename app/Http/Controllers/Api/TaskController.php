<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);

        $filters = $request->validate([
            'workspace_id' => ['nullable'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'parent_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'view' => ['nullable', 'string', Rule::in(['today', 'upcoming', 'completed', 'overdue'])],
            'due_before' => ['nullable', 'date'],
            'overdue' => ['nullable', 'boolean'],
            'blocked' => ['nullable', 'boolean'],
            'top_level' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['created_at', '-created_at', 'due_at', '-due_at', 'priority', 'title', '-title'])],
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
        $view = $filters['view'] ?? null;

        $tasks = Task::query()
            ->whereHas('project', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->with(['tags', 'assignee', 'dependencies', 'project'])
            ->withCount('subtasks')
            ->when($workspaceId && $workspaceId !== 'all', function (Builder $query) use ($workspaceId) {
                $query->where(function (Builder $inner) use ($workspaceId) {
                    $inner->where('workspace_id', $workspaceId)
                        ->orWhereHas('project', fn (Builder $p) => $p->where('workspace_id', $workspaceId));
                });
            })
            ->when($filters['project_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('project_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['assignee_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('user_id', $id))
            ->when($filters['parent_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('parent_id', $id))
            ->when($filters['due_before'] ?? null, fn (Builder $query, string $date) => $query->where('due_at', '<=', $date))
            ->when(filter_var($filters['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN) || $view === 'overdue', fn (Builder $query) => $query->overdue())
            ->when($view === 'today', function (Builder $query) {
                $query->whereNotIn('status', ['done', 'cancelled'])
                    ->where(function (Builder $inner) {
                        $inner->whereDate('due_at', '<=', today())
                            ->orWhereNull('due_at');
                    });
            })
            ->when($view === 'upcoming', fn (Builder $query) => $query->whereNotIn('status', ['done', 'cancelled'])->whereDate('due_at', '>', today()))
            ->when($view === 'completed', fn (Builder $query) => $query->whereIn('status', ['done', 'cancelled']))
            ->when(isset($filters['blocked']), fn (Builder $query) => filter_var($filters['blocked'], FILTER_VALIDATE_BOOLEAN)
                ? $query->blocked()
                : $query->unblocked())
            ->when($filters['top_level'] ?? null, fn (Builder $query) => $query->whereNull('parent_id'))
            ->when($term, function (Builder $query) use ($term): void {
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('title', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $filters['sort'] ?? '-created_at'))
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tags = $data['tags'] ?? null;
        unset($data['tags']);

        $project = Project::findOrFail($data['project_id']);
        $this->authorize('create', [Task::class, $project]);

        // Validate workspace alignment between task and project
        if (! empty($data['workspace_id']) && $project->workspace_id && (int) $data['workspace_id'] !== (int) $project->workspace_id) {
            abort(422, 'The task workspace must match the project workspace.');
        }

        if (empty($data['workspace_id'])) {
            $data['workspace_id'] = $project->workspace_id;
        }

        $data['user_id'] = array_key_exists('assignee_id', $data)
            ? $data['assignee_id']
            : $request->user()->id;
        unset($data['assignee_id']);

        $data['status'] ??= TaskStatus::Backlog->value;
        $data['priority'] ??= TaskPriority::Medium->value;
        $data['activity_history'] = [
            [
                'event' => 'created',
                'description' => 'Task created',
                'timestamp' => now()->toIso8601String(),
                'user' => $request->user()->name,
            ],
        ];

        $task = DB::transaction(function () use ($project, $data, $tags, $request): Task {
            $task = $project->tasks()->create($data);

            if ($tags !== null) {
                $this->syncTags($task, $request->user(), $tags);
            }

            return $task;
        });

        $this->notifyTask($task, $request->user(), TelegramService::EVENT_TASK_CREATED, '🚀 New task created');

        return TaskResource::make($this->loadForResponse($task))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($this->loadForResponse($task));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $data = $request->validated();
        $tags = $data['tags'] ?? null;
        unset($data['tags']);

        $previousStatus = $task->status;
        $previousPriority = $task->priority;
        $previousDueAt = $task->due_at?->toDateTimeString();
        $previousAssigneeId = (int) $task->user_id;

        if (array_key_exists('assignee_id', $data)) {
            $data['user_id'] = $data['assignee_id'];
            unset($data['assignee_id']);
        }

        // Track activity history
        $history = $task->activity_history ?? [];
        if (isset($data['status']) && $data['status'] !== $task->status->value) {
            $history[] = [
                'event' => 'status_change',
                'description' => "Status changed from {$task->status->value} to {$data['status']}",
                'timestamp' => now()->toIso8601String(),
                'user' => $request->user()->name,
            ];
        }
        if (isset($data['actual_time_spent_minutes']) && (int) $data['actual_time_spent_minutes'] !== (int) $task->actual_time_spent_minutes) {
            $history[] = [
                'event' => 'time_logged',
                'description' => "Time spent updated to {$data['actual_time_spent_minutes']} minutes",
                'timestamp' => now()->toIso8601String(),
                'user' => $request->user()->name,
            ];
        }
        $data['activity_history'] = $history;

        DB::transaction(function () use ($task, $data, $tags, $request): void {
            $task->update($data);

            if ($tags !== null) {
                $this->syncTags($task, $request->user(), $tags);
            }
        });

        $task = $task->refresh();

        $changes = [];
        if ($task->status !== $previousStatus) {
            $changes['Status'] = Str::headline($previousStatus->value).' → '.Str::headline($task->status->value);
        }
        if ($task->priority !== $previousPriority) {
            $changes['Priority'] = Str::headline($previousPriority->value).' → '.Str::headline($task->priority->value);
        }
        if ($task->due_at?->toDateTimeString() !== $previousDueAt) {
            $changes['Due date'] = $task->due_at?->format('M d, Y H:i') ?? 'Removed';
        }
        if ((int) $task->user_id !== $previousAssigneeId) {
            $changes['Assigned to'] = $task->assignee?->name ?? 'Unassigned';
        }

        if ($changes !== []) {
            $completed = $task->status === TaskStatus::Done && $previousStatus !== TaskStatus::Done;
            $event = $completed ? TelegramService::EVENT_TASK_COMPLETED : TelegramService::EVENT_TASK_UPDATED;

            $this->notifyTask(
                $task,
                $request->user(),
                $event,
                $completed ? '✅ Task completed' : '🔄 Task updated',
                $changes,
            );
        }

        return new TaskResource($this->loadForResponse($task));
    }

    public function destroy(Task $task): Response
    {
        $this->authorize('delete', $task);

        $task->delete();

        return response()->noContent();
    }

    /**
     * @param  array<string, string|null>  $extra
     */
    private function notifyTask(Task $task, User $actor, string $event, string $heading, array $extra = []): void
    {
        $task->loadMissing(['project.user', 'assignee']);

        $recipients = collect([$task->project?->user, $task->assignee])
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $fields = $extra + [
            'Task' => $task->title,
            'Project' => $task->project?->name,
            'Status' => $task->status ? Str::headline($task->status->value) : null,
            'Priority' => $task->priority ? Str::headline($task->priority->value) : null,
            'Assigned to' => $task->assignee?->name,
            'Due' => $task->due_at?->format('M d, Y H:i'),
            'By' => $actor->name,
        ];

        $this->telegram->sendToUsers($recipients, $event, $this->telegram->message($heading, $fields), [
            'workspace_id' => $task->workspace_id,
            'payload' => [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
            ],
        ]);
    }

    private function syncTags(Task $task, User $user, array $names): void
    {
        $ids = Collection::make($names)
            ->map(fn (string $name): string => trim($name))
            ->filter(fn (string $name): bool => Str::slug($name) !== '')
            ->unique(fn (string $name): string => Str::slug($name))
            ->map(fn (string $name): int => $user->tags()
                ->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])
                ->id)
            ->values()
            ->all();

        $task->tags()->sync($ids);
    }

    private function loadForResponse(Task $task): Task
    {
        return $task->load(['tags', 'assignee', 'dependencies', 'project'])->loadCount('subtasks');
    }

    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === 'priority') {
            $query->orderByRaw(
                "case priority when 'urgent' then 4 when 'high' then 3 when 'medium' then 2 else 1 end desc"
            )->orderByDesc('id');

            return;
        }

        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderByDesc('id');
    }
}
