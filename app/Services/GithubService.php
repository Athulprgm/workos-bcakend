<?php

namespace App\Services;

use App\Enums\BugStatus;
use App\Enums\TaskStatus;
use App\Models\Bug;
use App\Models\GithubAccount;
use App\Models\GithubLink;
use App\Models\GithubOauthState;
use App\Models\GithubRepositoryLink;
use App\Models\GithubWebhookEvent;
use App\Models\Task;
use App\Models\User;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GithubService
{
    private const GITHUB_AUTH_URL = 'https://github.com/login/oauth/authorize';

    private const GITHUB_TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private const GITHUB_API_BASE = 'https://api.github.com';

    /**
     * Generate GitHub OAuth authorization URL with CSRF state protection.
     */
    public function getAuthorizationUrl(User $user, ?string $redirectUrl = null): string
    {
        $clientId = config('services.github.client_id');
        $callbackUri = config('services.github.redirect_uri');

        if (empty($clientId)) {
            throw new Exception('GitHub Client ID is not configured. Please set GITHUB_CLIENT_ID in your environment.');
        }

        // Clean up any stale states for this user
        GithubOauthState::where('user_id', $user->id)
            ->where('expires_at', '<', now())
            ->delete();

        // Create cryptographically secure random state token
        $stateToken = Str::random(40);

        GithubOauthState::create([
            'user_id' => $user->id,
            'state' => $stateToken,
            'redirect_url' => $redirectUrl,
            'expires_at' => now()->addMinutes(15),
        ]);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $callbackUri,
            'scope' => 'repo,read:user,user:email',
            'state' => $stateToken,
            'allow_signup' => 'true',
        ]);

        return self::GITHUB_AUTH_URL.'?'.$params;
    }

    /**
     * Handle the OAuth callback, validate state CSRF, and obtain/persist access token.
     */
    public function handleCallback(string $code, string $state): GithubAccount
    {
        $oauthState = GithubOauthState::where('state', $state)->first();

        if (! $oauthState) {
            throw ValidationException::withMessages([
                'state' => ['Invalid or expired OAuth state token. Possible CSRF attempt.'],
            ]);
        }

        if ($oauthState->isExpired()) {
            $oauthState->delete();
            throw ValidationException::withMessages([
                'state' => ['GitHub authorization request has expired. Please try connecting again.'],
            ]);
        }

        $user = $oauthState->user;

        $clientId = config('services.github.client_id');
        $clientSecret = config('services.github.client_secret');
        $redirectUri = config('services.github.redirect_uri');

        // Exchange authorization code for access token. GitHub's token endpoint
        // expects form-encoded parameters, not a JSON body.
        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->post(self::GITHUB_TOKEN_URL, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'state' => $state,
            ]);

        if (! $tokenResponse->successful()) {
            Log::error('GitHub OAuth token exchange failed', [
                'status' => $tokenResponse->status(),
                'response' => $tokenResponse->json(),
            ]);
            throw new Exception('Failed to exchange authorization code with GitHub.');
        }

        $tokenData = $tokenResponse->json();

        if (isset($tokenData['error'])) {
            $errorDesc = $tokenData['error_description'] ?? $tokenData['error'];
            throw new Exception('GitHub OAuth error: '.$errorDesc);
        }

        $accessToken = $tokenData['access_token'] ?? null;
        if (! $accessToken) {
            throw new Exception('No access token received from GitHub.');
        }

        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? null;
        $tokenExpiresAt = $expiresIn ? now()->addSeconds((int) $expiresIn) : null;
        $scopeString = $tokenData['scope'] ?? '';
        $scopes = array_filter(array_map('trim', explode(',', $scopeString)));

        // Retrieve user profile from GitHub API
        $profile = $this->fetchUserProfile($accessToken);

        // Fetch primary email if not public in profile
        $primaryEmail = $profile['email'] ?? $this->fetchUserPrimaryEmail($accessToken);

        $account = GithubAccount::updateOrCreate(
            ['user_id' => $user->id],
            [
                'github_user_id' => (string) $profile['id'],
                'github_username' => $profile['login'],
                'name' => $profile['name'] ?? $profile['login'],
                'email' => $primaryEmail,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'html_url' => $profile['html_url'] ?? null,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $tokenExpiresAt,
                'scopes' => $scopes,
            ]
        );

        // Clean up the used state token
        $oauthState->delete();

        return $account;
    }

    /**
     * Disconnect GitHub connection for a user.
     */
    public function disconnect(User $user): void
    {
        $account = $user->githubAccount;
        if ($account) {
            $account->delete();
        }
    }

    /**
     * Fetch user profile from GitHub API.
     */
    public function fetchUserProfile(string $token): array
    {
        $response = $this->client($token)->get('/user');

        if (! $response->successful()) {
            $this->handleApiError($response, 'Failed to retrieve GitHub profile.');
        }

        return $response->json();
    }

    /**
     * Fetch user primary verified email if not included in profile.
     */
    public function fetchUserPrimaryEmail(string $token): ?string
    {
        $response = $this->client($token)->get('/user/emails');
        if ($response->successful()) {
            $emails = $response->json();
            if (is_array($emails)) {
                foreach ($emails as $email) {
                    if (! empty($email['primary']) && ! empty($email['verified'])) {
                        return $email['email'];
                    }
                }

                return $emails[0]['email'] ?? null;
            }
        }

        return null;
    }

    /**
     * List repositories accessible to the connected user.
     */
    public function getRepositories(GithubAccount $account, array $filters = []): array
    {
        $query = [
            'per_page' => min(100, max(1, (int) ($filters['per_page'] ?? 30))),
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'sort' => $filters['sort'] ?? 'updated',
            'direction' => $filters['direction'] ?? 'desc',
        ];

        if (! empty($filters['visibility'])) {
            $query['visibility'] = $filters['visibility']; // all, public, private
        }
        if (! empty($filters['affiliation'])) {
            $query['affiliation'] = $filters['affiliation']; // owner,collaborator,organization_member
        }

        $response = $this->clientFor($account)->get('/user/repos', $query);

        if (! $response->successful()) {
            $this->handleApiError($response, 'Failed to fetch repositories from GitHub.');
        }

        $repos = $response->json();
        $formatted = [];

        // Parse pagination headers
        $linkHeader = $response->header('Link');
        $hasNextPage = $linkHeader && str_contains($linkHeader, 'rel="next"');
        $hasPrevPage = $linkHeader && str_contains($linkHeader, 'rel="prev"');

        $searchTerm = isset($filters['q']) ? strtolower(trim($filters['q'])) : null;

        foreach ($repos as $repo) {
            if ($searchTerm) {
                $name = strtolower($repo['name'] ?? '');
                $desc = strtolower($repo['description'] ?? '');
                if (! str_contains($name, $searchTerm) && ! str_contains($desc, $searchTerm)) {
                    continue;
                }
            }

            $formatted[] = [
                'id' => $repo['id'],
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'description' => $repo['description'],
                'private' => (bool) $repo['private'],
                'visibility' => $repo['visibility'] ?? ($repo['private'] ? 'private' : 'public'),
                'primary_language' => $repo['language'],
                'default_branch' => $repo['default_branch'] ?? 'main',
                'html_url' => $repo['html_url'],
                'updated_at' => $repo['updated_at'],
                'pushed_at' => $repo['pushed_at'],
                'owner' => [
                    'login' => $repo['owner']['login'] ?? '',
                    'avatar_url' => $repo['owner']['avatar_url'] ?? '',
                    'html_url' => $repo['owner']['html_url'] ?? '',
                ],
                'stargazers_count' => $repo['stargazers_count'] ?? 0,
                'forks_count' => $repo['forks_count'] ?? 0,
                'open_issues_count' => $repo['open_issues_count'] ?? 0,
            ];
        }

        return [
            'data' => $formatted,
            'pagination' => [
                'current_page' => (int) ($filters['page'] ?? 1),
                'per_page' => (int) $query['per_page'],
                'has_next' => $hasNextPage,
                'has_prev' => $hasPrevPage,
            ],
            'rate_limit' => [
                'remaining' => (int) $response->header('X-RateLimit-Remaining'),
                'limit' => (int) $response->header('X-RateLimit-Limit'),
                'reset_at' => $response->header('X-RateLimit-Reset')
                    ? Carbon::createFromTimestamp((int) $response->header('X-RateLimit-Reset'))->toIso8601String()
                    : null,
            ],
        ];
    }

    /**
     * Get commits for a repository.
     */
    public function getCommits(GithubAccount $account, string $owner, string $repo, array $params = []): array
    {
        $query = [
            'per_page' => min(50, max(1, (int) ($params['per_page'] ?? 20))),
            'page' => max(1, (int) ($params['page'] ?? 1)),
        ];

        if (! empty($params['sha'])) {
            $query['sha'] = $params['sha']; // Branch or commit SHA
        }

        $response = $this->clientFor($account)
            ->get("/repos/{$owner}/{$repo}/commits", $query);

        if (! $response->successful()) {
            $this->handleApiError($response, "Failed to fetch commits for {$owner}/{$repo}.");
        }

        $commits = $response->json();
        $formatted = [];

        foreach ($commits as $c) {
            $sha = $c['sha'] ?? '';
            $commitObj = $c['commit'] ?? [];
            $authorObj = $c['author'] ?? [];

            $formatted[] = [
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'message' => $commitObj['message'] ?? '',
                'author' => [
                    'name' => $commitObj['author']['name'] ?? $authorObj['login'] ?? 'Unknown',
                    'email' => $commitObj['author']['email'] ?? null,
                    'login' => $authorObj['login'] ?? null,
                    'avatar_url' => $authorObj['avatar_url'] ?? null,
                ],
                'date' => $commitObj['author']['date'] ?? $commitObj['committer']['date'] ?? null,
                'html_url' => $c['html_url'] ?? "https://github.com/{$owner}/{$repo}/commit/{$sha}",
            ];
        }

        return [
            'data' => $formatted,
            'pagination' => [
                'current_page' => (int) ($params['page'] ?? 1),
                'per_page' => (int) $query['per_page'],
                'has_next' => (bool) ($response->header('Link') && str_contains($response->header('Link'), 'rel="next"')),
            ],
        ];
    }

    /**
     * Get branches for a repository.
     */
    public function getBranches(GithubAccount $account, string $owner, string $repo, ?string $defaultBranch = null): array
    {
        $response = $this->clientFor($account)
            ->get("/repos/{$owner}/{$repo}/branches", ['per_page' => 100]);

        if (! $response->successful()) {
            $this->handleApiError($response, "Failed to fetch branches for {$owner}/{$repo}.");
        }

        $branches = $response->json();
        $formatted = [];

        foreach ($branches as $branch) {
            $name = $branch['name'] ?? '';
            $commitSha = $branch['commit']['sha'] ?? '';

            $formatted[] = [
                'name' => $name,
                'commit_sha' => $commitSha,
                'commit_short_sha' => substr($commitSha, 0, 7),
                'commit_url' => $branch['commit']['url'] ?? null,
                'protected' => (bool) ($branch['protected'] ?? false),
                'is_default' => $defaultBranch ? ($name === $defaultBranch) : false,
                'html_url' => "https://github.com/{$owner}/{$repo}/tree/{$name}",
            ];
        }

        return $formatted;
    }

    /**
     * Get pull requests for a repository.
     */
    public function getPullRequests(GithubAccount $account, string $owner, string $repo, array $params = []): array
    {
        $query = [
            'state' => $params['state'] ?? 'all', // open, closed, all
            'per_page' => min(50, max(1, (int) ($params['per_page'] ?? 20))),
            'page' => max(1, (int) ($params['page'] ?? 1)),
            'sort' => 'updated',
            'direction' => 'desc',
        ];

        $response = $this->clientFor($account)
            ->get("/repos/{$owner}/{$repo}/pulls", $query);

        if (! $response->successful()) {
            $this->handleApiError($response, "Failed to fetch pull requests for {$owner}/{$repo}.");
        }

        $prs = $response->json();
        $formatted = [];

        foreach ($prs as $pr) {
            $merged = ! empty($pr['merged_at']);
            $state = $merged ? 'merged' : ($pr['state'] ?? 'open');

            $formatted[] = [
                'id' => $pr['id'],
                'number' => $pr['number'],
                'title' => $pr['title'],
                'state' => $state,
                'draft' => (bool) ($pr['draft'] ?? false),
                'html_url' => $pr['html_url'],
                'created_at' => $pr['created_at'],
                'updated_at' => $pr['updated_at'],
                'merged_at' => $pr['merged_at'] ?? null,
                'closed_at' => $pr['closed_at'] ?? null,
                'author' => [
                    'login' => $pr['user']['login'] ?? 'Unknown',
                    'avatar_url' => $pr['user']['avatar_url'] ?? null,
                    'html_url' => $pr['user']['html_url'] ?? null,
                ],
                'head_branch' => $pr['head']['ref'] ?? null,
                'base_branch' => $pr['base']['ref'] ?? null,
            ];
        }

        return [
            'data' => $formatted,
            'pagination' => [
                'current_page' => (int) ($params['page'] ?? 1),
                'per_page' => (int) $query['per_page'],
                'has_next' => (bool) ($response->header('Link') && str_contains($response->header('Link'), 'rel="next"')),
            ],
        ];
    }

    /**
     * Get issues (excluding pull requests, which GitHub returns in the same feed).
     */
    public function getIssues(GithubAccount $account, string $owner, string $repo, array $params = []): array
    {
        $query = [
            'state' => $params['state'] ?? 'all',
            'per_page' => min(50, max(1, (int) ($params['per_page'] ?? 30))),
            'page' => max(1, (int) ($params['page'] ?? 1)),
            'sort' => 'updated',
            'direction' => 'desc',
        ];

        $response = $this->clientFor($account)
            ->get("/repos/{$owner}/{$repo}/issues", $query);

        if (! $response->successful()) {
            $this->handleApiError($response, "Failed to fetch issues for {$owner}/{$repo}.");
        }

        $formatted = [];

        foreach ($response->json() as $issue) {
            if (isset($issue['pull_request'])) {
                continue;
            }

            $formatted[] = [
                'id' => $issue['id'],
                'number' => $issue['number'],
                'title' => $issue['title'],
                'state' => $issue['state'] ?? 'open',
                'body' => $issue['body'] ?? null,
                'html_url' => $issue['html_url'],
                'created_at' => $issue['created_at'],
                'updated_at' => $issue['updated_at'],
                'closed_at' => $issue['closed_at'] ?? null,
                'comments' => $issue['comments'] ?? 0,
                'labels' => collect($issue['labels'] ?? [])->pluck('name')->values()->all(),
                'author' => [
                    'login' => $issue['user']['login'] ?? 'Unknown',
                    'avatar_url' => $issue['user']['avatar_url'] ?? null,
                ],
            ];
        }

        return [
            'data' => $formatted,
            'pagination' => [
                'current_page' => (int) ($params['page'] ?? 1),
                'per_page' => (int) $query['per_page'],
                'has_next' => (bool) ($response->header('Link') && str_contains($response->header('Link'), 'rel="next"')),
            ],
        ];
    }

    /**
     * Fetch one repository, used to prove the connected account can access it
     * before linking it to a Work OS project.
     */
    public function getRepository(GithubAccount $account, string $owner, string $repo): array
    {
        $response = $this->clientFor($account)->get("/repos/{$owner}/{$repo}");

        if (! $response->successful()) {
            $this->handleApiError($response, "Repository {$owner}/{$repo} is not accessible.");
        }

        $repository = $response->json();

        return [
            'id' => (string) $repository['id'],
            'name' => $repository['name'],
            'full_name' => $repository['full_name'],
            'owner' => $repository['owner']['login'] ?? $owner,
            'default_branch' => $repository['default_branch'] ?? 'main',
            'private' => (bool) ($repository['private'] ?? false),
            'html_url' => $repository['html_url'] ?? null,
        ];
    }

    /**
     * Apply a verified webhook to linked tasks and bugs.
     *
     * Only repository links whose GitHub repository ID matches the payload are
     * touched, and the unique delivery record above supplies idempotency.
     */
    public function applyWebhookToLinks(string $event, array $payload): int
    {
        $repository = $payload['repository'] ?? [];
        $repositoryId = isset($repository['id']) ? (string) $repository['id'] : null;
        $fullName = $repository['full_name'] ?? null;

        if (! $repositoryId || ! $fullName) {
            return 0;
        }

        $repositoryLinks = GithubRepositoryLink::query()
            ->where('repository_id', $repositoryId)
            ->where('full_name', $fullName)
            ->get();

        if ($repositoryLinks->isEmpty()) {
            return 0;
        }

        $action = $payload['action'] ?? null;
        $updated = 0;

        foreach ($repositoryLinks as $repositoryLink) {
            $repositoryLink->update([
                'last_synced_at' => now(),
                'last_sync_status' => 'success',
                'last_sync_error' => null,
            ]);

            if (! $repositoryLink->auto_sync) {
                continue;
            }

            if ($event === 'issues' && $repositoryLink->sync_issues) {
                $issue = $payload['issue'] ?? null;

                if ($issue && isset($issue['number'])) {
                    $updated += $this->applyGithubItem(
                        $repositoryLink,
                        'issue',
                        $issue,
                        $action,
                    );
                }
            }

            if ($event === 'pull_request' && $repositoryLink->sync_pull_requests) {
                $pullRequest = $payload['pull_request'] ?? null;

                if ($pullRequest && isset($pullRequest['number'])) {
                    $updated += $this->applyGithubItem(
                        $repositoryLink,
                        'pull_request',
                        $pullRequest,
                        $action,
                    );
                }
            }
        }

        return $updated;
    }

    /**
     * Update one linked GitHub item and any local task/bug attached to it.
     */
    private function applyGithubItem(
        GithubRepositoryLink $repositoryLink,
        string $type,
        array $item,
        ?string $action,
    ): int {
        $state = $type === 'pull_request' && ! empty($item['merged_at']) ? 'merged' : ($item['state'] ?? 'open');

        $links = GithubLink::query()
            ->where('github_repository_link_id', $repositoryLink->id)
            ->where('github_type', $type)
            ->where('github_number', $item['number'])
            ->get();

        if ($links->isEmpty()) {
            return 0;
        }

        foreach ($links as $link) {
            $link->update([
                'title' => $item['title'] ?? $link->title,
                'state' => $state,
                'html_url' => $item['html_url'] ?? $link->html_url,
                'last_event' => $action,
                'synced_at' => now(),
            ]);

            $local = $link->local;

            if ($local instanceof Bug) {
                $this->applyGithubStateToBug($local, $type, $state, $action);
            } elseif ($local instanceof Task) {
                $this->applyGithubStateToTask($local, $type, $state, $action, $item);
            }
        }

        return $links->count();
    }

    private function applyGithubStateToBug(Bug $bug, string $type, string $state, ?string $action): void
    {
        $newStatus = null;

        if ($type === 'issue') {
            $newStatus = $action === 'closed' ? BugStatus::Fixed->value : ($action === 'reopened' ? BugStatus::Open->value : null);
        }

        if ($type === 'pull_request' && $state === 'merged') {
            $newStatus = BugStatus::Fixed->value;
        }

        if ($newStatus === null || $bug->status?->value === $newStatus) {
            $bug->save();

            return;
        }

        $bug->status = $newStatus;
        $bug->sync_hash = $bug->syncHash();
        $bug->save();
    }

    private function applyGithubStateToTask(Task $task, string $type, string $state, ?string $action, array $item): void
    {
        if ($type !== 'pull_request' || $state !== 'merged') {
            return;
        }

        if ($task->status instanceof TaskStatus && $task->status->isClosed()) {
            return;
        }

        $history = $task->activity_history ?? [];
        $history[] = [
            'type' => 'github.pull_request.merged',
            'message' => 'GitHub pull request #'.($item['number'] ?? '?').' merged: '.($item['title'] ?? ''),
            'url' => $item['html_url'] ?? null,
            'at' => now()->toIso8601String(),
        ];

        $task->update([
            'status' => TaskStatus::Review->value,
            'activity_history' => array_slice($history, -50),
            'completion_notes' => trim(($task->completion_notes ?? '')."\nLinked GitHub PR merged: ".($item['html_url'] ?? '')),
        ]);
    }

    /**
     * Verify GitHub Webhook HMAC-SHA256 signature using timing-safe comparison.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
    {
        $secret = config('services.github.webhook_secret');

        // If no secret is configured yet in environment, we reject unless in testing with explicit bypass
        if (empty($secret)) {
            Log::warning('GitHub Webhook rejected: GITHUB_WEBHOOK_SECRET is not configured.');

            return false;
        }

        if (! $signatureHeader || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expectedSignature, $signatureHeader);
    }

    /**
     * Process GitHub webhook event with idempotency guarantees.
     */
    public function processWebhookEvent(string $deliveryId, string $event, array $payload, ?string $action = null): GithubWebhookEvent
    {
        // Check for duplicate delivery (idempotency check)
        $existing = GithubWebhookEvent::where('delivery_id', $deliveryId)->first();
        if ($existing) {
            return $existing;
        }

        $repoName = $payload['repository']['full_name'] ?? null;
        $sender = $payload['sender']['login'] ?? null;

        $webhookEvent = GithubWebhookEvent::create([
            'delivery_id' => $deliveryId,
            'event' => $event,
            'action' => $action ?? ($payload['action'] ?? null),
            'repository' => $repoName,
            'sender' => $sender,
            'payload' => $payload,
            'processed' => true,
        ]);

        return $webhookEvent;
    }

    /**
     * Refresh an expiring OAuth token when GitHub issued a refresh token.
     *
     * GitHub only returns a refresh token when expiring user tokens are
     * enabled for the OAuth app, so accounts without one keep working.
     */
    private function refreshTokenIfNeeded(GithubAccount $account): void
    {
        if (! $account->refresh_token || ! $account->token_expires_at) {
            return;
        }

        if ($account->token_expires_at->isAfter(now()->addMinute())) {
            return;
        }

        $response = Http::asForm()->acceptJson()->post(self::GITHUB_TOKEN_URL, [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        $tokenData = (array) $response->json();

        if (! $response->successful() || ! empty($tokenData['error']) || empty($tokenData['access_token'])) {
            Log::warning('GitHub OAuth token refresh failed', [
                'status' => $response->status(),
                'error' => $tokenData['error'] ?? null,
            ]);

            throw new Exception('GitHub authorization has expired. Please reconnect your GitHub account.');
        }

        $account->update([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => isset($tokenData['expires_in'])
                ? now()->addSeconds((int) $tokenData['expires_in'])
                : null,
            'scopes' => isset($tokenData['scope'])
                ? array_values(array_filter(array_map('trim', explode(',', (string) $tokenData['scope']))))
                : $account->scopes,
        ]);
    }

    /**
     * Create an authenticated client, refreshing the account token first when needed.
     */
    private function clientFor(GithubAccount $account): PendingRequest
    {
        $this->refreshTokenIfNeeded($account);

        return $this->client($account->access_token);
    }

    /**
     * Create authenticated GitHub API HTTP client.
     */
    private function client(string $token): PendingRequest
    {
        return Http::baseUrl(self::GITHUB_API_BASE)
            ->withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'WorkOS-Platform',
            ])
            ->timeout(15);
    }

    /**
     * Handle GitHub API errors gracefully, reporting rate limits or token revocation.
     */
    private function handleApiError(Response $response, string $defaultMessage): void
    {
        $status = $response->status();
        $body = $response->json();
        $message = $body['message'] ?? $defaultMessage;

        if ($status === 401) {
            throw new Exception('GitHub access token has been revoked or expired. Please reconnect your GitHub account.');
        }

        if ($status === 403 && str_contains(strtolower($message), 'rate limit')) {
            $resetAt = $response->header('X-RateLimit-Reset')
                ? Carbon::createFromTimestamp((int) $response->header('X-RateLimit-Reset'))->diffForHumans()
                : 'shortly';
            throw new Exception("GitHub API rate limit exceeded. Limit resets {$resetAt}.");
        }

        Log::warning('GitHub API error', [
            'status' => $status,
            'message' => $message,
            'headers' => $response->headers(),
        ]);

        throw new Exception($message);
    }
}
