<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportVisibility;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkReportResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkReport;
use App\Models\Workspace;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkReportController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['mine', 'shared', 'public'])],
            'type' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'visibility' => ['nullable', Rule::enum(ReportVisibility::class)],
            'status' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:255'],
            'workspace_id' => ['nullable'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $scope = $filters['scope'] ?? 'mine';
        $workspaceId = $filters['workspace_id'] ?? $request->header('X-Workspace-Id');

        if ($scope === 'mine' && $workspaceId && $workspaceId !== 'all') {
            $workspaceId = (int) $workspaceId;
            if (! $user->workspaces()->where('id', $workspaceId)->exists()) {
                abort(403, 'Unauthorized access to this workspace.');
            }
        }

        $query = match ($scope) {
            'shared' => WorkReport::query()
                ->where('shared_with_gm', true)
                ->where('gm_user_id', $user->id),
            'public' => WorkReport::query()
                ->where('visibility', ReportVisibility::Public->value),
            default => WorkReport::query()->where('user_id', $user->id),
        };

        $reports = $query
            ->with('generalManager')
            ->with('user')
            ->when($scope === 'mine' && $workspaceId && $workspaceId !== 'all', fn (Builder $q) => $q->where('workspace_id', $workspaceId))
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('type', $type))
            ->when($filters['visibility'] ?? null, fn (Builder $q, string $visibility) => $q->where('visibility', $visibility))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('period_start', $date))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('period_start', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('period_start', '<=', $date))
            ->when($filters['q'] ?? null, function (Builder $q, string $search) {
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('project_name', 'like', "%{$search}%")
                        ->orWhere('work_description', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return WorkReportResource::collection($reports);
    }

    /**
     * People who can be selected as a General Manager for a report.
     */
    public function generalManagers(Request $request): JsonResponse
    {
        // The directory is intentionally role-scoped. Owners can still share
        // with a named GM by email, but we never enumerate every account.
        $people = User::query()
            ->whereKeyNot($request->user()->id)
            ->whereIn('role', ['general_manager', 'admin', 'super_admin'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        return response()->json(['data' => $people]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'type' => ['required', 'string', 'in:daily,weekly,monthly,custom'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $workspace = Workspace::where('id', $data['workspace_id'])
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Calculate period bounds
        $type = $data['type'];
        if (! empty($data['period_start']) && ! empty($data['period_end'])) {
            $start = Carbon::parse($data['period_start'])->startOfDay();
            $end = Carbon::parse($data['period_end'])->endOfDay();
        } elseif ($type === 'daily') {
            $start = today()->startOfDay();
            $end = today()->endOfDay();
        } elseif ($type === 'weekly') {
            $start = now()->startOfWeek()->startOfDay();
            $end = now()->endOfWeek()->endOfDay();
        } elseif ($type === 'monthly') {
            $start = now()->startOfMonth()->startOfDay();
            $end = now()->endOfMonth()->endOfDay();
        } else {
            $start = now()->subDays(7)->startOfDay();
            $end = now()->endOfDay();
        }

        // Query REAL database records in this workspace and time window:
        // 1. Projects active or with tasks in this workspace
        $projects = Project::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->get();

        $projectIds = $projects->pluck('id')->all();

        // 2. Completed tasks in period
        $completedTasks = Task::where(function ($q) use ($workspace, $projectIds) {
            $q->where('workspace_id', $workspace->id)
                ->orWhereIn('project_id', $projectIds);
        })
            ->where('status', TaskStatus::Done->value)
            ->whereBetween('completed_at', [$start, $end])
            ->with('project')
            ->get();

        // 3. Work in progress tasks
        $inProgressTasks = Task::where(function ($q) use ($workspace, $projectIds) {
            $q->where('workspace_id', $workspace->id)
                ->orWhereIn('project_id', $projectIds);
        })
            ->whereIn('status', [TaskStatus::InProgress->value, TaskStatus::Review->value])
            ->with('project')
            ->get();

        // 4. Pending / backlog tasks
        $pendingTasks = Task::where(function ($q) use ($workspace, $projectIds) {
            $q->where('workspace_id', $workspace->id)
                ->orWhereIn('project_id', $projectIds);
        })
            ->whereIn('status', [TaskStatus::Backlog->value, TaskStatus::Todo->value])
            ->with('project')
            ->get();

        // 5. Blocked tasks
        $blockedTasks = Task::where(function ($q) use ($workspace, $projectIds) {
            $q->where('workspace_id', $workspace->id)
                ->orWhereIn('project_id', $projectIds);
        })
            ->where('status', TaskStatus::Blocked->value)
            ->with('project')
            ->get();

        // 6. Work logs in period
        $workLogs = WorkLog::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->with(['project', 'task'])
            ->get();

        $totalTimeMinutes = $workLogs->sum('time_spent_minutes');

        // Build arrays without fabricating facts
        $projectsWorked = $projects->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'status' => $p->status->value,
            'tasks_count' => $p->tasks()->count(),
        ])->values()->all();

        $completedWorkItems = $completedTasks->map(fn ($t) => [
            'task_id' => $t->id,
            'title' => $t->title,
            'project' => $t->project?->name ?? 'General',
            'completed_at' => $t->completed_at?->format('Y-m-d H:i'),
        ])->values()->all();

        // Add work logs that describe completed deliverables
        foreach ($workLogs as $log) {
            $completedWorkItems[] = [
                'log_id' => $log->id,
                'title' => $log->work_description,
                'project' => $log->project?->name ?? 'General',
                'time_spent' => $log->time_spent_minutes > 0 ? "{$log->time_spent_minutes}m" : null,
                'date' => $log->date->format('Y-m-d'),
            ];
        }

        $wipItems = $inProgressTasks->map(fn ($t) => [
            'task_id' => $t->id,
            'title' => $t->title,
            'project' => $t->project?->name ?? 'General',
            'priority' => $t->priority->value,
            'due_at' => $t->due_at?->format('Y-m-d'),
        ])->values()->all();

        $pendingItems = $pendingTasks->map(fn ($t) => [
            'task_id' => $t->id,
            'title' => $t->title,
            'project' => $t->project?->name ?? 'General',
            'priority' => $t->priority->value,
            'due_at' => $t->due_at?->format('Y-m-d'),
        ])->values()->all();

        $issuesBlockers = $blockedTasks->map(fn ($t) => [
            'task_id' => $t->id,
            'title' => $t->title,
            'project' => $t->project?->name ?? 'General',
            'reason' => 'Task status set to Blocked',
        ])->values()->all();

        foreach ($workLogs->filter(fn ($l) => ! empty($l->problems_encountered)) as $log) {
            $issuesBlockers[] = [
                'log_id' => $log->id,
                'title' => $log->problems_encountered,
                'project' => $log->project?->name ?? 'General',
                'reason' => 'Reported in work log',
            ];
        }

        $nextSteps = [];
        foreach ($workLogs->filter(fn ($l) => ! empty($l->next_steps)) as $log) {
            $nextSteps[] = [
                'log_id' => $log->id,
                'step' => $log->next_steps,
                'project' => $log->project?->name ?? 'General',
            ];
        }
        if (empty($nextSteps) && $wipItems) {
            foreach (array_slice($wipItems, 0, 3) as $wip) {
                $nextSteps[] = [
                    'step' => "Continue progress on {$wip['title']}",
                    'project' => $wip['project'],
                ];
            }
        }

        // Formatted Markdown text suitable for copy-paste to WhatsApp / Email
        $formattedDate = $start->format('M d, Y').($start->isSameDay($end) ? '' : ' - '.$end->format('M d, Y'));
        $title = ucfirst($type)." Work Report: {$workspace->name} ({$formattedDate})";

        $hours = intdiv($totalTimeMinutes, 60);
        $minutes = $totalTimeMinutes % 60;
        $timeStr = $totalTimeMinutes > 0 ? "{$hours}h {$minutes}m" : 'Not recorded';

        // Build clean tabular structure for daily summary table
        $dailyTableRows = [];
        $counter = 1;
        foreach ($completedWorkItems as $item) {
            $dailyTableRows[] = [
                'index' => $counter++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'Completed',
                'status_tone' => 'success',
                'time_spent' => $item['time_spent'] ?? '—',
                'notes' => $item['date'] ?? 'Done',
            ];
        }
        foreach ($wipItems as $item) {
            $dailyTableRows[] = [
                'index' => $counter++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'In Progress',
                'status_tone' => 'warning',
                'time_spent' => '—',
                'notes' => isset($item['due_at']) && $item['due_at'] ? "Due: {$item['due_at']}" : ($item['priority'] ?? 'Active'),
            ];
        }
        foreach ($pendingItems as $item) {
            $dailyTableRows[] = [
                'index' => $counter++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'Pending',
                'status_tone' => 'neutral',
                'time_spent' => '—',
                'notes' => $item['priority'] ?? 'Backlog',
            ];
        }
        foreach ($issuesBlockers as $item) {
            $dailyTableRows[] = [
                'index' => $counter++,
                'title' => $item['title'] ?? '',
                'project' => $item['project'] ?? 'General',
                'status' => 'Blocked',
                'status_tone' => 'danger',
                'time_spent' => '—',
                'notes' => $item['reason'] ?? 'Blocker',
            ];
        }

        $md = "# {$title}\n\n";
        $md .= "**Company / Workspace:** {$workspace->name}\n";
        $md .= "**Reporting Period:** {$formattedDate}\n";
        $md .= "**Tracked Time:** {$timeStr}\n\n";

        // Markdown Table Format
        $md .= "## 📊 Work Deliverables Summary Table\n\n";
        if (empty($dailyTableRows)) {
            $md .= "_No work items or logs recorded for this period._\n\n";
        } else {
            $md .= "| # | Task / Deliverable | Project | Status | Time Spent | Notes |\n";
            $md .= "|---|--------------------|---------|--------|------------|-------|\n";
            foreach ($dailyTableRows as $row) {
                $cleanTitle = str_replace('|', '/', $row['title']);
                $cleanProject = str_replace('|', '/', $row['project']);
                $cleanNotes = str_replace('|', '/', $row['notes']);
                $md .= "| {$row['index']} | {$cleanTitle} | {$cleanProject} | {$row['status']} | {$row['time_spent']} | {$cleanNotes} |\n";
            }
            $md .= "\n";
        }

        $md .= "## 1. Projects Worked On\n";
        if (empty($projectsWorked)) {
            $md .= "- No active projects recorded in this workspace.\n";
        } else {
            foreach ($projectsWorked as $p) {
                $md .= "- **{$p['name']}** ({$p['status']}, {$p['tasks_count']} total tasks)\n";
            }
        }
        $md .= "\n";

        $md .= "## 2. Completed Work\n";
        if (empty($completedWorkItems)) {
            $md .= "- No completed tasks or work logs recorded in this period.\n";
        } else {
            foreach ($completedWorkItems as $item) {
                $extra = isset($item['time_spent']) && $item['time_spent'] ? " ({$item['time_spent']})" : '';
                $md .= "- [x] [{$item['project']}] {$item['title']}{$extra}\n";
            }
        }
        $md .= "\n";

        $md .= "## 3. Work In Progress\n";
        if (empty($wipItems)) {
            $md .= "- No tasks currently marked in progress.\n";
        } else {
            foreach ($wipItems as $item) {
                $due = isset($item['due_at']) && $item['due_at'] ? " (Due: {$item['due_at']})" : '';
                $md .= "- [ ] [{$item['project']}] {$item['title']}{$due}\n";
            }
        }
        $md .= "\n";

        $md .= "## 4. Pending Tasks\n";
        if (empty($pendingItems)) {
            $md .= "- No pending tasks queued.\n";
        } else {
            foreach (array_slice($pendingItems, 0, 5) as $item) {
                $md .= "- [ ] [{$item['project']}] {$item['title']}\n";
            }
            if (count($pendingItems) > 5) {
                $remaining = count($pendingItems) - 5;
                $md .= "- ... and {$remaining} other queued tasks.\n";
            }
        }
        $md .= "\n";

        $md .= "## 5. Issues & Blockers\n";
        if (empty($issuesBlockers)) {
            $md .= "- No blockers or issues encountered.\n";
        } else {
            foreach ($issuesBlockers as $b) {
                $md .= "- ⚠️ [{$b['project']}] {$b['title']}\n";
            }
        }
        $md .= "\n";

        $md .= "## 6. Next Steps\n";
        if (empty($nextSteps)) {
            $md .= "- Proceed according to sprint plan.\n";
        } else {
            foreach ($nextSteps as $ns) {
                $md .= "- ➡️ [{$ns['project']}] {$ns['step']}\n";
            }
        }

        // WhatsApp-friendly text
        $whatsAppText = "📊 *{$title}*\n";
        $whatsAppText .= "🏢 *Company:* {$workspace->name}\n";
        $whatsAppText .= "⏱ *Time Logged:* {$timeStr}\n\n";
        $whatsAppText .= "*✅ Completed:*\n";
        if (empty($completedWorkItems)) {
            $whatsAppText .= "• None recorded\n";
        } else {
            foreach (array_slice($completedWorkItems, 0, 8) as $item) {
                $whatsAppText .= "• [{$item['project']}] {$item['title']}\n";
            }
        }
        $whatsAppText .= "\n*⏳ In Progress:*\n";
        if (empty($wipItems)) {
            $whatsAppText .= "• None\n";
        } else {
            foreach (array_slice($wipItems, 0, 5) as $item) {
                $whatsAppText .= "• [{$item['project']}] {$item['title']}\n";
            }
        }
        if (! empty($issuesBlockers)) {
            $whatsAppText .= "\n*⚠️ Blockers:*\n";
            foreach ($issuesBlockers as $b) {
                $whatsAppText .= "• {$b['title']}\n";
            }
        }

        return response()->json([
            'data' => [
                'workspace_id' => $workspace->id,
                'type' => $type,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'title' => $title,
                'company_name' => $workspace->name,
                'table_data' => $dailyTableRows,
                'projects_worked' => $projectsWorked,
                'completed_work' => $completedWorkItems,
                'work_in_progress' => $wipItems,
                'pending_tasks' => $pendingItems,
                'issues_blockers' => $issuesBlockers,
                'time_spent_minutes' => $totalTimeMinutes,
                'next_steps' => $nextSteps,
                'raw_content' => $md,
                'whatsapp_content' => $whatsAppText,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'type' => ['nullable', 'string', 'in:daily,weekly,monthly,custom'],
            'date' => ['nullable', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'project_name' => ['nullable', 'string', 'max:255'],
            'work_description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:Completed,Complete,In Progress,Progress,in_progress,Pending'],
            'visibility' => ['nullable', Rule::enum(ReportVisibility::class)],
            'shared_with_gm' => ['nullable', 'boolean'],
            'gm_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'gm_email' => ['nullable', 'email'],
            'projects_worked' => ['nullable', 'array'],
            'completed_work' => ['nullable', 'array'],
            'work_in_progress' => ['nullable', 'array'],
            'pending_tasks' => ['nullable', 'array'],
            'issues_blockers' => ['nullable', 'array'],
            'time_spent_minutes' => ['nullable', 'integer', 'min:0'],
            'next_steps' => ['nullable', 'array'],
            'raw_content' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        if (empty($data['workspace_id'])) {
            $headerWs = $request->header('X-Workspace-Id');
            if (! empty($headerWs) && $headerWs !== 'all') {
                $data['workspace_id'] = (int) $headerWs;
            } else {
                $data['workspace_id'] = $user->workspaces()->first()?->id;
            }
        }

        if (empty($data['workspace_id']) || ! $user->workspaces()->where('id', $data['workspace_id'])->exists()) {
            abort(403, 'Unauthorized access to this workspace.');
        }

        $workspace = $user->workspaces()->where('id', $data['workspace_id'])->first();

        $reportDate = $data['date'] ?? $data['period_start'] ?? today()->toDateString();
        $data['period_start'] = $data['period_start'] ?? $reportDate;
        $data['period_end'] = $data['period_end'] ?? $reportDate;
        $data['type'] = $data['type'] ?? 'daily';
        
        $rawStatus = $data['status'] ?? 'Completed';
        if (in_array(strtolower($rawStatus), ['complete', 'completed'])) {
            $data['status'] = 'Completed';
        } elseif (in_array(strtolower($rawStatus), ['progress', 'in progress', 'in_progress'])) {
            $data['status'] = 'In Progress';
        } else {
            $data['status'] = 'Pending';
        }

        $projectName = $data['project_name'] ?? ($data['projects_worked'][0]['name'] ?? ($workspace?->name ?? 'General'));
        $data['project_name'] = $projectName;
        $data['company_name'] = $data['company_name'] ?? ($workspace?->name ?? $projectName);

        $workDesc = $data['work_description'] ?? ($data['raw_content'] ?? '');
        $data['work_description'] = $workDesc;

        $formattedDate = Carbon::parse($data['period_start'])->format('M d, Y');
        $data['title'] = $data['title'] ?? "{$projectName} Report ({$formattedDate})";

        if (empty($data['projects_worked'])) {
            $data['projects_worked'] = [['id' => 1, 'name' => $projectName, 'status' => 'active', 'tasks_count' => 1]];
        }

        if (empty($data['raw_content'])) {
            $data['raw_content'] = "# {$data['title']}\n\n**Project:** {$projectName}\n**Date:** {$formattedDate}\n**Status:** {$data['status']}\n\n## Description\n{$workDesc}\n";
        }

        $deliverableItem = [
            'title' => $workDesc ?: $data['title'],
            'project' => $projectName,
            'description' => $workDesc,
            'date' => $data['period_start'],
            'status' => $data['status'],
        ];

        if ($data['status'] === 'Completed' && empty($data['completed_work'])) {
            $data['completed_work'] = [$deliverableItem];
        } elseif ($data['status'] === 'In Progress' && empty($data['work_in_progress'])) {
            $data['work_in_progress'] = [$deliverableItem];
        } elseif ($data['status'] === 'Pending' && empty($data['pending_tasks'])) {
            $data['pending_tasks'] = [$deliverableItem];
        }

        $data = $this->applySharingFields($data, $user);
        unset($data['date']);

        $report = $user->workReports()->create($data);
        $report = $report->load('generalManager');

        $this->notifyWorkReport($report, $user);

        return WorkReportResource::make($report)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Public index of work reports for the complete staff report portal.
     * Accessible without authentication.
     */
    public function publicIndex(Request $request): AnonymousResourceCollection
    {
        $query = WorkReport::query()
            ->with(['generalManager', 'user'])
            ->orderByDesc('period_end')
            ->orderByDesc('id');

        if ($request->filled('workspace_id') && $request->input('workspace_id') !== 'all') {
            $query->where('workspace_id', (int) $request->input('workspace_id'));
        }

        $reports = $query->paginate($request->integer('per_page', 50));

        return WorkReportResource::collection($reports);
    }

    /**
     * Public work report view for staff or external viewers via share token.
     * No authentication required.
     */
    public function publicShow(string $token): JsonResponse
    {
        $report = WorkReport::query()
            ->where('share_token', $token)
            ->orWhere(function (Builder $q) use ($token): void {
                if (is_numeric($token)) {
                    $q->where('id', (int) $token);
                }
            })
            ->with(['generalManager', 'user'])
            ->first();

        if (! $report) {
            abort(404, 'Work report not found or this link is no longer available.');
        }

        return response()->json([
            'data' => new WorkReportResource($report),
        ]);
    }

    public function show(Request $request, WorkReport $workReport): WorkReportResource
    {
        $this->authorizeReportRead($request->user(), $workReport);

        return new WorkReportResource($workReport->load(['generalManager', 'user']));
    }

    public function update(Request $request, WorkReport $workReport): WorkReportResource
    {
        if ((int) $workReport->user_id !== (int) $request->user()->id) {
            abort(403, 'Unauthorized access to this report.');
        }

        $data = $request->validate([
            'date' => ['sometimes', 'nullable', 'date'],
            'period_start' => ['sometimes', 'nullable', 'date'],
            'period_end' => ['sometimes', 'nullable', 'date'],
            'project_name' => ['sometimes', 'required', 'string', 'max:255'],
            'work_description' => ['sometimes', 'required', 'string'],
            'status' => ['sometimes', 'required', 'string', 'in:Completed,Complete,In Progress,Progress,in_progress,Pending'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'raw_content' => ['sometimes', 'nullable', 'string'],
            'completed_work' => ['sometimes', 'nullable', 'array'],
            'work_in_progress' => ['sometimes', 'nullable', 'array'],
            'pending_tasks' => ['sometimes', 'nullable', 'array'],
            'issues_blockers' => ['sometimes', 'nullable', 'array'],
            'next_steps' => ['sometimes', 'nullable', 'array'],
            'visibility' => ['sometimes', Rule::enum(ReportVisibility::class)],
            'shared_with_gm' => ['sometimes', 'boolean'],
            'gm_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'gm_email' => ['sometimes', 'nullable', 'email'],
        ]);

        if (isset($data['date'])) {
            $data['period_start'] = $data['date'];
            $data['period_end'] = $data['date'];
            unset($data['date']);
        }

        $projectName = $data['project_name'] ?? $workReport->project_name;
        $rawStatus = $data['status'] ?? $workReport->status;
        if (in_array(strtolower($rawStatus), ['complete', 'completed'])) {
            $status = 'Completed';
        } elseif (in_array(strtolower($rawStatus), ['progress', 'in progress', 'in_progress'])) {
            $status = 'In Progress';
        } else {
            $status = 'Pending';
        }
        $data['status'] = $status;
        $workDesc = $data['work_description'] ?? $workReport->work_description;
        $periodStart = $data['period_start'] ?? ($workReport->period_start?->toDateString() ?? today()->toDateString());

        if (isset($data['project_name']) || isset($data['work_description']) || isset($data['status']) || isset($data['period_start'])) {
            $formattedDate = Carbon::parse($periodStart)->format('M d, Y');
            if (empty($data['title'])) {
                $data['title'] = "{$projectName} Report ({$formattedDate})";
            }
            if (empty($data['raw_content'])) {
                $data['raw_content'] = "# {$data['title']}\n\n**Project:** {$projectName}\n**Date:** {$formattedDate}\n**Status:** {$status}\n\n## Description\n{$workDesc}\n";
            }

            $deliverableItem = [
                'title' => $workDesc ?: $data['title'],
                'project' => $projectName,
                'description' => $workDesc,
                'date' => $periodStart,
                'status' => $status,
            ];

            if ($status === 'Completed') {
                $data['completed_work'] = [$deliverableItem];
                $data['work_in_progress'] = [];
                $data['pending_tasks'] = [];
            } elseif ($status === 'In Progress') {
                $data['completed_work'] = [];
                $data['work_in_progress'] = [$deliverableItem];
                $data['pending_tasks'] = [];
            } elseif ($status === 'Pending') {
                $data['completed_work'] = [];
                $data['work_in_progress'] = [];
                $data['pending_tasks'] = [$deliverableItem];
            }
        }

        $data = $this->applySharingFields($data, $request->user());

        $workReport->update($data);

        return new WorkReportResource($workReport->load('generalManager'));
    }

    public function destroy(Request $request, WorkReport $workReport): Response
    {
        if ((int) $workReport->user_id !== (int) $request->user()->id) {
            abort(403, 'Unauthorized access to this report.');
        }

        $workReport->delete();

        return response()->noContent();
    }

    private function notifyWorkReport(WorkReport $report, User $owner): void
    {
        $period = null;

        if ($report->period_start && $report->period_end) {
            $period = $report->period_start->isSameDay($report->period_end)
                ? $report->period_start->format('M d, Y')
                : $report->period_start->format('M d, Y').' - '.$report->period_end->format('M d, Y');
        }

        $time = $report->time_spent_minutes > 0
            ? sprintf('%dh %02dm', intdiv($report->time_spent_minutes, 60), $report->time_spent_minutes % 60)
            : null;

        $context = [
            'workspace_id' => $report->workspace_id,
            'payload' => ['work_report_id' => $report->id],
        ];

        $this->telegram->sendToUser($owner, TelegramService::EVENT_WORK_REPORT_CREATED, $this->telegram->message('📊 Work report generated', [
            'Report' => $report->title,
            'Company' => $report->company_name,
            'Period' => $period,
            'Time' => $time,
            'Visibility' => $report->visibility?->value,
            'Shared with' => $report->generalManager?->name,
        ]), $context);

        $generalManager = $report->generalManager;

        if ($generalManager instanceof User && $report->shared_with_gm && (int) $generalManager->id !== (int) $owner->id) {
            $this->telegram->sendToUser($generalManager, TelegramService::EVENT_WORK_REPORT_SHARED, $this->telegram->message('📥 Work report shared with you', [
                'Report' => $report->title,
                'Company' => $report->company_name,
                'Period' => $period,
                'From' => $owner->name,
            ]), $context);
        }
    }

    /**
     * Private reports are visible only to their owner or an explicitly named GM.
     * Public reports are readable by any authenticated workspace member.
     */
    private function authorizeReportRead(User $user, WorkReport $report): void
    {
        $isOwner = (int) $report->user_id === (int) $user->id;

        if ($isOwner || $report->isSharedWith($user) || $report->isPublic()) {
            return;
        }

        abort(403, 'Unauthorized access to this report.');
    }

    /**
     * Resolve GM sharing fields without accepting arbitrary user IDs silently.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applySharingFields(array $data, User $owner): array
    {
        $hasEmail = array_key_exists('gm_email', $data);
        $hasId = array_key_exists('gm_user_id', $data);

        if ($hasEmail) {
            $email = $data['gm_email'];
            unset($data['gm_email']);

            if ($email) {
                $gm = User::where('email', strtolower(trim($email)))->first();

                if (! $gm) {
                    throw ValidationException::withMessages([
                        'gm_email' => 'No WorkOS account exists for that email address.',
                    ]);
                }

                $data['gm_user_id'] = $gm->id;
            } else {
                $data['gm_user_id'] = null;
            }

            $hasId = true;
        }

        if ($hasId) {
            $gmId = $data['gm_user_id'] ?? null;

            if ($gmId !== null && (int) $gmId === (int) $owner->id) {
                throw ValidationException::withMessages([
                    'gm_user_id' => 'You cannot share a report with yourself as General Manager.',
                ]);
            }

            $data['shared_with_gm'] = $gmId !== null;
            $data['shared_at'] = $gmId !== null ? ($data['shared_at'] ?? now()) : null;
        } elseif (array_key_exists('shared_with_gm', $data) && ! $data['shared_with_gm']) {
            $data['gm_user_id'] = null;
            $data['shared_at'] = null;
        }

        if (($data['shared_with_gm'] ?? false) && empty($data['gm_user_id']) && ! array_key_exists('gm_user_id', $data)) {
            throw ValidationException::withMessages([
                'gm_user_id' => 'Select a General Manager before enabling GM sharing.',
            ]);
        }

        return $data;
    }
}
