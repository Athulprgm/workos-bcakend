<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GithubAccount;
use App\Services\GithubService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GithubController extends Controller
{
    public function __construct(
        protected GithubService $githubService
    ) {}

    /**
     * Generate GitHub OAuth connect URL.
     */
    public function connect(Request $request): JsonResponse
    {
        $redirectUrl = $request->input('redirect_url');

        try {
            $url = $this->githubService->getAuthorizationUrl($request->user(), $redirectUrl);

            return response()->json([
                'url' => $url,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle the GitHub OAuth callback.
     */
    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $frontendUrl = config('services.github.frontend_url', 'http://localhost:5173');

        if (! $code || ! $state) {
            $errorMessage = $request->query('error_description') ?? 'Authorization code or state parameter missing.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $errorMessage], 400);
            }

            return redirect("{$frontendUrl}/github?error=".urlencode($errorMessage));
        }

        try {
            $account = $this->githubService->handleCallback($code, $state);

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'GitHub account connected successfully.',
                    'account' => [
                        'id' => $account->id,
                        'username' => $account->github_username,
                        'name' => $account->name,
                        'avatar_url' => $account->avatar_url,
                    ],
                ]);
            }

            return redirect("{$frontendUrl}/github?status=connected&user=".urlencode($account->github_username));
        } catch (Exception $e) {
            Log::error('GitHub callback error', ['error' => $e->getMessage()]);

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect("{$frontendUrl}/github?error=".urlencode($e->getMessage()));
        }
    }

    /**
     * Get the current user's connected GitHub account status.
     */
    public function status(Request $request): JsonResponse
    {
        $account = $request->user()->githubAccount()->first();

        if (! $account) {
            return response()->json([
                'connected' => false,
                'account' => null,
            ]);
        }

        return response()->json([
            'connected' => true,
            'account' => [
                'id' => $account->id,
                'github_user_id' => $account->github_user_id,
                'username' => $account->github_username,
                'name' => $account->name,
                'email' => $account->email,
                'avatar_url' => $account->avatar_url,
                'html_url' => $account->html_url,
                'scopes' => $account->scopes ?? [],
                'connected_at' => $account->created_at?->toIso8601String(),
                'updated_at' => $account->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Disconnect GitHub connection.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $this->githubService->disconnect($request->user());

        return response()->json([
            'message' => 'GitHub account disconnected successfully.',
            'connected' => false,
        ]);
    }

    /**
     * List user repositories.
     */
    public function repositories(Request $request): JsonResponse
    {
        $account = $this->getAuthenticatedGithubAccount($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'visibility' => ['nullable', 'in:all,public,private'],
            'affiliation' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:created,updated,pushed,full_name'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->githubService->getRepositories($account, $filters);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get commits for a repository.
     */
    public function commits(Request $request, string $owner, string $repo): JsonResponse
    {
        $account = $this->getAuthenticatedGithubAccount($request);

        $params = $request->validate([
            'sha' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->githubService->getCommits($account, $owner, $repo, $params);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get branches for a repository.
     */
    public function branches(Request $request, string $owner, string $repo): JsonResponse
    {
        $account = $this->getAuthenticatedGithubAccount($request);
        $defaultBranch = $request->query('default_branch');

        try {
            $branches = $this->githubService->getBranches($account, $owner, $repo, $defaultBranch);

            return response()->json(['data' => $branches]);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get issues for a repository (pull requests are excluded).
     */
    public function issues(Request $request, string $owner, string $repo): JsonResponse
    {
        $account = $this->getAuthenticatedGithubAccount($request);

        $params = $request->validate([
            'state' => ['nullable', 'in:open,closed,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->githubService->getIssues($account, $owner, $repo, $params);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get pull requests for a repository.
     */
    public function pullRequests(Request $request, string $owner, string $repo): JsonResponse
    {
        $account = $this->getAuthenticatedGithubAccount($request);

        $params = $request->validate([
            'state' => ['nullable', 'in:open,closed,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $result = $this->githubService->getPullRequests($account, $owner, $repo, $params);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Get the active GitHub account or abort with 400.
     */
    private function getAuthenticatedGithubAccount(Request $request): GithubAccount
    {
        $account = $request->user()->githubAccount()->first();

        if (! $account) {
            abort(response()->json([
                'message' => 'No GitHub account connected. Please connect your GitHub account to access repositories.',
                'connected' => false,
            ], 400));
        }

        return $account;
    }
}
