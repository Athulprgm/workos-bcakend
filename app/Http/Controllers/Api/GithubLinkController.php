<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GithubLinkResource;
use App\Http\Resources\GithubRepositoryLinkResource;
use App\Models\Bug;
use App\Models\GithubLink;
use App\Models\GithubRepositoryLink;
use App\Models\Project;
use App\Models\Task;
use App\Services\GithubService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GithubLinkController extends Controller
{
    public function __construct(protected GithubService $githubService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $links = $request->user()->githubRepositoryLinks()
            ->with('project')
            ->withCount('links')
            ->orderByDesc('id')
            ->get();

        return GithubRepositoryLinkResource::collection($links);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'owner' => ['required', 'string', 'max:100'],
            'repo' => ['required', 'string', 'max:150'],
        ]);

        $user = $request->user();
        $project = Project::findOrFail($data['project_id']);

        if ((int) $project->user_id !== (int) $user->id) {
            abort(403, 'You cannot link a repository to a project you do not own.');
        }

        $account = $user->githubAccount;

        if (! $account) {
            throw ValidationException::withMessages([
                'github' => 'Connect a GitHub account before linking repositories.',
            ]);
        }

        try {
            $repository = $this->githubService->getRepository($account, $data['owner'], $data['repo']);
        } catch (Exception $e) {
            throw ValidationException::withMessages(['repo' => $e->getMessage()]);
        }

        $duplicate = GithubRepositoryLink::query()
            ->where(function ($query) use ($user, $repository): void {
                $query->where('user_id', $user->id)->where('repository_id', $repository['id']);
            })
            ->orWhere(function ($query) use ($project, $repository): void {
                $query->where('project_id', $project->id)->where('repository_id', $repository['id']);
            })
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'repo' => 'This repository is already linked to a Work OS project.',
            ]);
        }

        $link = GithubRepositoryLink::create([
            'user_id' => $user->id,
            'github_account_id' => $account->id,
            'project_id' => $project->id,
            'repository_id' => $repository['id'],
            'owner' => $repository['owner'],
            'name' => $repository['name'],
            'full_name' => $repository['full_name'],
            'default_branch' => $repository['default_branch'],
            'sync_issues' => true,
            'sync_pull_requests' => true,
            'auto_sync' => true,
            'last_sync_status' => 'never',
        ]);

        return GithubRepositoryLinkResource::make($link->load('project'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, GithubRepositoryLink $link): GithubRepositoryLinkResource
    {
        $this->ensureOwner($request, $link);

        $data = $request->validate([
            'sync_issues' => ['sometimes', 'boolean'],
            'sync_pull_requests' => ['sometimes', 'boolean'],
            'auto_sync' => ['sometimes', 'boolean'],
        ]);

        $link->update($data);

        return new GithubRepositoryLinkResource($link->load('project'));
    }

    public function destroy(Request $request, GithubRepositoryLink $link): Response
    {
        $this->ensureOwner($request, $link);

        $link->delete();

        return response()->noContent();
    }

    /**
     * List the repository's issues with Work OS association state.
     */
    public function issues(Request $request, GithubRepositoryLink $link): JsonResponse
    {
        $this->ensureOwner($request, $link);

        $params = $request->validate([
            'state' => ['nullable', Rule::in(['open', 'closed', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $account = $request->user()->githubAccount;

        if (! $account) {
            throw ValidationException::withMessages(['github' => 'Connect a GitHub account first.']);
        }

        try {
            $result = $this->githubService->getIssues($account, $link->owner, $link->name, $params);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $associations = $link->links()
            ->where('github_type', 'issue')
            ->get()
            ->keyBy('github_number');

        $issues = collect($result['data'])->map(function (array $issue) use ($associations): array {
            $association = $associations->get($issue['number']);

            return [
                ...$issue,
                'linked' => (bool) $association,
                'linked_to' => $association ? [
                    'id' => $association->id,
                    'local_type' => $association->local_type,
                    'local_id' => $association->local_id,
                ] : null,
            ];
        })->all();

        $link->update([
            'last_synced_at' => now(),
            'last_sync_status' => 'success',
            'last_sync_error' => null,
        ]);

        return response()->json([
            'data' => $issues,
            'pagination' => $result['pagination'] ?? null,
        ]);
    }

    /**
     * All GitHub issue/PR associations for the current user.
     */
    public function linkedItems(Request $request): AnonymousResourceCollection
    {
        $links = $request->user()->githubLinks()
            ->with('repositoryLink')
            ->orderByDesc('synced_at')
            ->limit(200)
            ->get();

        return GithubLinkResource::collection($links);
    }

    /**
     * Associate a GitHub issue or pull request with a task or bug.
     */
    public function linkItem(Request $request, GithubRepositoryLink $link): JsonResponse
    {
        $this->ensureOwner($request, $link);

        $data = $request->validate([
            'github_type' => ['required', Rule::in(['issue', 'pull_request'])],
            'github_number' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:24'],
            'html_url' => ['nullable', 'url', 'max:2048'],
            'local_type' => ['required', Rule::in(['task', 'bug'])],
            'local_id' => ['required', 'integer'],
        ]);

        $local = $this->resolveLocalRecord($link, $data['local_type'], (int) $data['local_id']);

        $existing = GithubLink::query()
            ->where('github_repository_link_id', $link->id)
            ->where('github_type', $data['github_type'])
            ->where('github_number', $data['github_number'])
            ->first();

        if ($existing && ($existing->local_type !== $data['local_type'] || (int) $existing->local_id !== (int) $data['local_id'])) {
            throw ValidationException::withMessages([
                'github_number' => 'That GitHub item is already associated with another Work OS record. Remove the existing link first.',
            ]);
        }

        $githubLink = GithubLink::updateOrCreate(
            [
                'github_repository_link_id' => $link->id,
                'github_type' => $data['github_type'],
                'github_number' => $data['github_number'],
            ],
            [
                'user_id' => $request->user()->id,
                'title' => $data['title'],
                'state' => $data['state'],
                'html_url' => $data['html_url'] ?? null,
                'local_type' => $data['local_type'],
                'local_id' => $local->id,
                'last_event' => 'manual_link',
                'synced_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'GitHub item linked.',
            'data' => new GithubLinkResource($githubLink),
        ], 201);
    }

    public function unlinkItem(Request $request, GithubRepositoryLink $link, GithubLink $githubLink): Response
    {
        $this->ensureOwner($request, $link);

        if ((int) $githubLink->github_repository_link_id !== (int) $link->id) {
            abort(404);
        }

        $githubLink->delete();

        return response()->noContent();
    }

    private function ensureOwner(Request $request, GithubRepositoryLink $link): void
    {
        if ((int) $link->user_id !== (int) $request->user()->id) {
            abort(403, 'You do not own this repository link.');
        }
    }

    private function resolveLocalRecord(GithubRepositoryLink $link, string $type, int $id): Task|Bug
    {
        if ($type === 'task') {
            return Task::query()
                ->whereKey($id)
                ->where('project_id', $link->project_id)
                ->firstOrFail();
        }

        return Bug::query()
            ->whereKey($id)
            ->where('project_id', $link->project_id)
            ->firstOrFail();
    }
}
