<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Project::class);

        $filters = $request->validate([
            'workspace_id' => ['nullable'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'include_archived' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['name', '-name', 'due_at', '-due_at', 'created_at', '-created_at', 'priority', '-priority'])],
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
        $includeArchived = filter_var($filters['include_archived'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $projects = $request->user()->projects()
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn (Builder $q) => $q->whereIn('status', ['done', 'completed']),
            ])
            ->when($workspaceId && $workspaceId !== 'all', fn (Builder $query) => $query->where('workspace_id', $workspaceId))
            ->when(! $includeArchived, fn (Builder $query) => $query->whereNull('archived_at'))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($term, function (Builder $query) use ($term): void {
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $filters['sort'] ?? '-created_at'))
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = Project::uniqueSlugFor($request->user(), $data['name']);
        $data['status'] ??= ProjectStatus::Active->value;
        $data['priority'] ??= 'medium';

        // Auto-assign workspace if none supplied
        if (empty($data['workspace_id'])) {
            $headerWorkspace = $request->header('X-Workspace-Id');
            if ($headerWorkspace && $headerWorkspace !== 'all' && $request->user()->workspaces()->where('id', (int) $headerWorkspace)->exists()) {
                $data['workspace_id'] = (int) $headerWorkspace;
            } else {
                $defaultWorkspace = $request->user()->workspaces()->active()->first();
                if ($defaultWorkspace) {
                    $data['workspace_id'] = $defaultWorkspace->id;
                }
            }
        }

        $project = $request->user()->projects()->create($data);

        return ProjectResource::make($project->loadCount([
            'tasks',
            'tasks as completed_tasks_count' => fn (Builder $q) => $q->whereIn('status', ['done', 'completed']),
        ]))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        $this->authorize('view', $project);

        return new ProjectResource($project->loadCount([
            'tasks',
            'tasks as completed_tasks_count' => fn (Builder $q) => $q->whereIn('status', ['done', 'completed']),
        ]));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $data = $request->validated();

        if (array_key_exists('name', $data) && $data['name'] !== $project->name) {
            $data['slug'] = Project::uniqueSlugFor($request->user(), $data['name'], $project->id);
        }

        $project->update($data);

        return new ProjectResource($project->loadCount([
            'tasks',
            'tasks as completed_tasks_count' => fn (Builder $q) => $q->whereIn('status', ['done', 'completed']),
        ]));
    }

    public function destroy(Project $project): Response
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->noContent();
    }

    private function applySort(Builder $query, string $sort): void
    {
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderByDesc('id');
    }
}
