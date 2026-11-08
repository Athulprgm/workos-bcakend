<?php

namespace App\Http\Controllers\Api;

use App\Enums\BugPriority;
use App\Enums\BugStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BugResource;
use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BugController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Bug::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::enum(BugStatus::class)],
            'priority' => ['nullable', Rule::enum(BugPriority::class)],
            'project_id' => ['nullable', 'integer'],
            'tester_id' => ['nullable', 'integer'],
            'validated' => ['nullable', 'boolean'],
            'source' => ['nullable', Rule::in(['manual', 'google_sheets', 'github'])],
            'sort' => ['nullable', Rule::in(['created_at', 'updated_at', 'priority', 'status', 'number'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Bug::query()
            ->visibleTo($request->user())
            ->with(['project', 'tester', 'creator', 'validator', 'githubLinks']);

        $this->applyFilters($query, $request, $filters);

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        if ($sort === 'priority') {
            $query->orderByRaw(
                "case priority when 'critical' then 4 when 'high' then 3 when 'medium' then 2 else 1 end {$direction}"
            );
        } else {
            $query->orderBy($sort, $direction);
        }

        $bugs = $query->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return BugResource::collection($bugs);
    }

    public function stats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bug::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
        ]);

        $query = Bug::query()
            ->visibleTo($request->user())
            ->when($filters['project_id'] ?? null, fn (Builder $q, int $projectId) => $q->where('project_id', $projectId));

        $statusCounts = (clone $query)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $priorityCounts = (clone $query)
            ->select('priority', DB::raw('count(*) as aggregate'))
            ->groupBy('priority')
            ->pluck('aggregate', 'priority');

        $byStatus = [];
        foreach (BugStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($statusCounts[$status->value] ?? 0);
        }

        $byPriority = [];
        foreach (BugPriority::cases() as $priority) {
            $byPriority[$priority->value] = (int) ($priorityCounts[$priority->value] ?? 0);
        }

        return response()->json([
            'data' => [
                'total' => (int) $query->count(),
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
                'open' => (int) (clone $query)->where('status', BugStatus::Open->value)->count(),
                'active' => (int) (clone $query)->whereIn('status', [
                    BugStatus::Open->value,
                    BugStatus::InProgress->value,
                    BugStatus::Retesting->value,
                ])->count(),
                'validated' => (int) (clone $query)->where('validated', true)->count(),
                'unvalidated' => (int) (clone $query)->where('validated', false)->count(),
                'reported_last_7_days' => (int) (clone $query)->where('created_at', '>=', now()->subDays(7))->count(),
                'projects' => (int) (clone $query)->distinct()->count('project_id'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Bug::class);

        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:20000'],
            'priority' => ['nullable', Rule::enum(BugPriority::class)],
            'status' => ['nullable', Rule::enum(BugStatus::class)],
            'tester_id' => ['nullable', 'integer', 'exists:users,id'],
            'tester_name' => ['nullable', 'string', 'max:255'],
            'testing_notes' => ['nullable', 'string', 'max:20000'],
            'validated' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $project = $this->resolveProject($user, (int) $data['project_id']);

        $bug = DB::transaction(function () use ($data, $project, $user): Bug {
            // Lock the project row so concurrent reporters cannot allocate the
            // same sequence number.
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $testerId = $data['tester_id'] ?? ($user->isTester() ? $user->id : null);

            if ($testerId !== null) {
                $tester = User::find($testerId);

                if (! $tester || $tester->role !== 'tester') {
                    throw ValidationException::withMessages([
                        'tester_id' => 'Select a valid tester account.',
                    ]);
                }
            }

            $validated = (bool) ($data['validated'] ?? false);

            $bug = Bug::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'number' => Bug::nextNumberFor($project),
                'title' => $data['title'],
                'details' => $data['details'] ?? null,
                'priority' => $data['priority'] ?? BugPriority::Medium->value,
                'status' => $data['status'] ?? BugStatus::Open->value,
                'tester_id' => $testerId,
                'tester_name' => $data['tester_name'] ?? null,
                'created_by' => $user->id,
                'testing_notes' => $data['testing_notes'] ?? null,
                'validated' => $validated,
                'validated_by' => $validated ? $user->id : null,
                'validated_at' => $validated ? now() : null,
                'source' => 'manual',
            ]);

            $bug->sync_hash = $bug->syncHash();
            $bug->save();

            return $bug;
        });

        $bug = $bug->load(['project', 'tester', 'creator', 'validator', 'githubLinks']);

        $this->notifyBug($bug, $user, TelegramService::EVENT_BUG_CREATED, '🐞 New bug reported');

        return BugResource::make($bug)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Bug $bug): BugResource
    {
        $this->authorize('view', $bug);

        return new BugResource($bug->load(['project', 'tester', 'creator', 'validator', 'githubLinks']));
    }

    public function update(Request $request, Bug $bug): BugResource
    {
        $this->authorize('update', $bug);

        $data = $request->validate([
            'project_id' => ['sometimes', 'integer', 'exists:projects,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'details' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'priority' => ['sometimes', Rule::enum(BugPriority::class)],
            'status' => ['sometimes', Rule::enum(BugStatus::class)],
            'tester_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'tester_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'testing_notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'validated' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        $previousStatus = $bug->status;
        $previousPriority = $bug->priority;
        $previousTesterId = $bug->tester_id;
        $previousValidated = (bool) $bug->validated;

        if (array_key_exists('project_id', $data)) {
            $project = $this->resolveProject($user, (int) $data['project_id']);
            $data['workspace_id'] = $project->workspace_id;
        }

        if (array_key_exists('tester_id', $data) && $data['tester_id'] !== null) {
            $tester = User::find($data['tester_id']);

            if (! $tester || $tester->role !== 'tester') {
                throw ValidationException::withMessages([
                    'tester_id' => 'Select a valid tester account.',
                ]);
            }
        }

        if (array_key_exists('validated', $data)) {
            $data['validated_by'] = $data['validated'] ? $user->id : null;
            $data['validated_at'] = $data['validated'] ? now() : null;
        }

        $oldStatus = $previousStatus?->value;

        $bug->update($data);
        $bug->sync_hash = $bug->syncHash();
        $bug->save();

        // A status transition is appended to the task-style activity trail so
        // sync consumers can tell local edits from untouched rows.
        if ($oldStatus !== $bug->status?->value) {
            $bug->external_updated_at = now();
            $bug->save();
        }

        $bug = $bug->load(['project', 'tester', 'creator', 'validator', 'githubLinks']);

        $changes = [];
        if ($bug->status !== $previousStatus) {
            $changes['Status'] = Str::headline((string) $previousStatus?->value).' → '.Str::headline($bug->status->value);
        }
        if ($bug->priority !== $previousPriority) {
            $changes['Priority'] = Str::headline((string) $previousPriority?->value).' → '.Str::headline($bug->priority->value);
        }
        if ((int) $bug->tester_id !== (int) $previousTesterId) {
            $changes['Tester'] = $bug->displayTesterName() ?? 'Unassigned';
        }
        if ((bool) $bug->validated !== $previousValidated) {
            $changes['Validated'] = $bug->validated ? 'Yes' : 'No';
        }

        if ($changes !== []) {
            $heading = match ($bug->status) {
                BugStatus::Fixed => '✅ Bug fixed',
                BugStatus::Closed => '🔒 Bug closed',
                default => '🔄 Bug updated',
            };

            $this->notifyBug($bug, $user, TelegramService::EVENT_BUG_UPDATED, $heading, $changes);
        }

        return new BugResource($bug);
    }

    public function destroy(Request $request, Bug $bug): Response
    {
        $this->authorize('delete', $bug);

        $bug->delete();

        return response()->noContent();
    }

    /**
     * Projects available to the caller in the Testing module.
     */
    public function projects(Request $request): JsonResponse
    {
        $user = $request->user();

        $projects = $user->isTester()
            ? Project::query()->whereIn('id', $user->accessibleProjectIds() ?? [])->orderBy('name')->get()
            : $user->projects()->orderBy('name')->get();

        return response()->json([
            'data' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'workspace_id' => $project->workspace_id,
            ]),
        ]);
    }

    /**
     * @param  array<string, string|null>  $extra
     */
    private function notifyBug(Bug $bug, User $actor, string $event, string $heading, array $extra = []): void
    {
        $bug->loadMissing(['project.user', 'tester', 'creator']);

        $recipients = collect([$bug->project?->user, $bug->tester, $bug->creator])
            ->filter(fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $fields = $extra + [
            'Bug' => $bug->reference().' - '.$bug->title,
            'Project' => $bug->project?->name,
            'Status' => Str::headline($bug->status->value),
            'Priority' => Str::headline($bug->priority->value),
            'Tester' => $bug->displayTesterName(),
            'Reported by' => $bug->creator?->name,
            'By' => $actor->name,
        ];

        $this->telegram->sendToUsers($recipients, $event, $this->telegram->message($heading, $fields), [
            'workspace_id' => $bug->workspace_id,
            'payload' => [
                'bug_id' => $bug->id,
                'project_id' => $bug->project_id,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, Request $request, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['priority'] ?? null, fn (Builder $q, string $priority) => $q->where('priority', $priority))
            ->when($filters['project_id'] ?? null, fn (Builder $q, int $projectId) => $q->where('project_id', $projectId))
            ->when($filters['tester_id'] ?? null, fn (Builder $q, int $testerId) => $q->where('tester_id', $testerId))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source));

        if ($request->has('validated')) {
            $query->where('validated', filter_var($request->query('validated'), FILTER_VALIDATE_BOOLEAN));
        }

        $term = $filters['search'] ?? null;

        if ($term) {
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('details', 'like', "%{$term}%")
                    ->orWhere('testing_notes', 'like', "%{$term}%")
                    ->orWhere('tester_name', 'like', "%{$term}%")
                    ->orWhere('number', ctype_digit($term) ? (int) $term : -1)
                    ->orWhereHas('project', fn (Builder $project) => $project->where('name', 'like', "%{$term}%"));
            });
        }
    }

    /**
     * Resolve a project the caller is authorized to work with.
     */
    private function resolveProject(User $user, int $projectId): Project
    {
        $project = Project::findOrFail($projectId);

        if ($user->isTester()) {
            if (! in_array($project->id, $user->accessibleProjectIds() ?? [], true)) {
                abort(403, 'You are not authorized to access this project.');
            }

            return $project;
        }

        if ((int) $project->user_id !== (int) $user->id) {
            abort(403, 'You are not authorized to access this project.');
        }

        return $project;
    }
}
