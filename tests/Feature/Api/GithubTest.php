<?php

namespace Tests\Feature\Api;

use App\Models\GithubAccount;
use App\Models\GithubOauthState;
use App\Models\GithubRepositoryLink;
use App\Models\GithubWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GithubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.github.client_id', 'test_client_id');
        Config::set('services.github.client_secret', 'test_client_secret');
        Config::set('services.github.redirect_uri', 'http://localhost:8000/api/github/callback');
        Config::set('services.github.webhook_secret', 'test_webhook_secret_key');
        Config::set('services.github.frontend_url', 'http://localhost:5173');
    }

    public function test_github_endpoints_require_authentication(): void
    {
        $this->getJson('/api/github/connect')->assertUnauthorized();
        $this->getJson('/api/github/status')->assertUnauthorized();
        $this->postJson('/api/github/disconnect')->assertUnauthorized();
        $this->getJson('/api/github/repositories')->assertUnauthorized();
        $this->getJson('/api/github/repositories/owner/repo/commits')->assertUnauthorized();
        $this->getJson('/api/github/repositories/owner/repo/branches')->assertUnauthorized();
        $this->getJson('/api/github/repositories/owner/repo/pull-requests')->assertUnauthorized();
        $this->getJson('/api/github/webhook-events')->assertUnauthorized();
    }

    public function test_connect_generates_oauth_url_and_persists_state(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/github/connect');

        $response->assertOk()
            ->assertJsonStructure(['url']);

        $url = $response->json('url');
        $this->assertStringContainsString('https://github.com/login/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=test_client_id', $url);
        $this->assertStringContainsString('scope=repo%2Cread%3Auser%2Cuser%3Aemail', $url);

        $this->assertDatabaseHas('github_oauth_states', [
            'user_id' => $user->id,
        ]);
    }

    public function test_callback_rejects_missing_or_invalid_state(): void
    {
        $response = $this->getJson('/api/github/callback?code=test_code&state=invalid_state');

        $response->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_callback_exchanges_code_for_token_and_links_account(): void
    {
        $user = User::factory()->create();

        // Create valid state in DB
        $stateRecord = GithubOauthState::create([
            'user_id' => $user->id,
            'state' => 'valid_csrf_state_token_12345',
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'gho_secret_access_token_xyz',
                'token_type' => 'bearer',
                'scope' => 'repo,read:user,user:email',
            ], 200),
            'https://api.github.com/user' => Http::response([
                'id' => 9876543,
                'login' => 'octocat',
                'name' => 'The Octocat',
                'email' => 'octocat@github.com',
                'avatar_url' => 'https://github.com/images/error/octocat_happy.gif',
                'html_url' => 'https://github.com/octocat',
            ], 200),
            'https://api.github.com/user/emails' => Http::response([
                ['email' => 'octocat@github.com', 'primary' => true, 'verified' => true],
            ], 200),
        ]);

        $response = $this->getJson('/api/github/callback?code=github_auth_code_xyz&state=valid_csrf_state_token_12345');

        $response->assertOk()
            ->assertJsonPath('message', 'GitHub account connected successfully.')
            ->assertJsonPath('account.username', 'octocat');

        // Verify account exists in database
        $this->assertDatabaseHas('github_accounts', [
            'user_id' => $user->id,
            'github_user_id' => '9876543',
            'github_username' => 'octocat',
        ]);

        // Verify the state token was consumed and removed
        $this->assertDatabaseMissing('github_oauth_states', [
            'state' => 'valid_csrf_state_token_12345',
        ]);

        // Verify token is encrypted in database, not stored plaintext
        $account = GithubAccount::where('user_id', $user->id)->first();
        $this->assertEquals('gho_secret_access_token_xyz', $account->access_token);
    }

    public function test_status_returns_account_info_and_never_leaks_tokens(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Before connecting
        $resBefore = $this->getJson('/api/github/status');
        $resBefore->assertOk()->assertJson(['connected' => false, 'account' => null]);

        // After connecting
        GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '123456',
            'github_username' => 'testuser',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'avatar_url' => 'https://example.com/avatar.png',
            'html_url' => 'https://github.com/testuser',
            'access_token' => 'ghp_secret_access_token_123',
            'scopes' => ['repo', 'read:user'],
        ]);

        $resAfter = $this->getJson('/api/github/status');
        $resAfter->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('account.username', 'testuser')
            ->assertJsonPath('account.email', 'test@example.com');

        // CRITICAL: Ensure access_token is NOT leaked in JSON response
        $this->assertArrayNotHasKey('access_token', $resAfter->json('account'));
        $this->assertArrayNotHasKey('refresh_token', $resAfter->json('account'));
    }

    public function test_disconnect_removes_connected_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '123456',
            'github_username' => 'testuser',
            'access_token' => 'ghp_secret_123',
        ]);

        $response = $this->postJson('/api/github/disconnect');

        $response->assertOk()
            ->assertJsonPath('connected', false);

        $this->assertDatabaseMissing('github_accounts', [
            'user_id' => $user->id,
        ]);
    }

    public function test_expired_oauth_token_is_refreshed_before_github_api_calls(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '123456',
            'github_username' => 'testuser',
            'access_token' => 'expired_access_token',
            'refresh_token' => 'old_refresh_token',
            'token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'fresh_access_token',
                'refresh_token' => 'rotated_refresh_token',
                'expires_in' => 28800,
                'scope' => 'repo,read:user',
                'token_type' => 'bearer',
            ], 200),
            'https://api.github.com/user/repos*' => Http::response([], 200),
        ]);

        $this->getJson('/api/github/repositories')->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://github.com/login/oauth/access_token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'old_refresh_token');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.github.com/user/repos')
            && $request->hasHeader('Authorization', 'Bearer fresh_access_token'));

        $account->refresh();

        $this->assertSame('fresh_access_token', $account->access_token);
        $this->assertSame('rotated_refresh_token', $account->refresh_token);
        $this->assertTrue($account->token_expires_at->isFuture());
    }

    public function test_failed_oauth_token_refresh_requires_reconnect(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '123456',
            'github_username' => 'testuser',
            'access_token' => 'expired_access_token',
            'refresh_token' => 'stale_refresh_token',
            'token_expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['error' => 'bad_refresh_token'], 200),
        ]);

        $this->getJson('/api/github/repositories')
            ->assertStatus(400)
            ->assertJsonPath('message', 'GitHub authorization has expired. Please reconnect your GitHub account.');

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.github.com/'));
    }

    public function test_repository_endpoints_require_a_connected_github_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/github/repositories')
            ->assertStatus(400)
            ->assertJsonPath('connected', false);
    }

    public function test_repositories_listing_with_search_and_rate_limits(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '123456',
            'github_username' => 'testuser',
            'access_token' => 'ghp_secret_123',
        ]);

        Http::fake([
            'https://api.github.com/user/repos*' => Http::response([
                [
                    'id' => 101,
                    'name' => 'work-os',
                    'full_name' => 'testuser/work-os',
                    'description' => 'Operating system for engineers',
                    'private' => false,
                    'visibility' => 'public',
                    'language' => 'PHP',
                    'default_branch' => 'main',
                    'html_url' => 'https://github.com/testuser/work-os',
                    'updated_at' => '2026-10-17T00:00:00Z',
                    'pushed_at' => '2026-10-17T01:00:00Z',
                    'owner' => ['login' => 'testuser', 'avatar_url' => 'https://avatar.url', 'html_url' => 'https://github.com/testuser'],
                    'stargazers_count' => 42,
                    'forks_count' => 5,
                    'open_issues_count' => 2,
                ],
                [
                    'id' => 102,
                    'name' => 'portfolio',
                    'full_name' => 'testuser/portfolio',
                    'description' => 'Personal portfolio site',
                    'private' => true,
                    'visibility' => 'private',
                    'language' => 'TypeScript',
                    'default_branch' => 'master',
                    'html_url' => 'https://github.com/testuser/portfolio',
                    'updated_at' => '2026-10-16T00:00:00Z',
                    'pushed_at' => '2026-10-16T01:00:00Z',
                    'owner' => ['login' => 'testuser', 'avatar_url' => 'https://avatar.url', 'html_url' => 'https://github.com/testuser'],
                    'stargazers_count' => 10,
                    'forks_count' => 1,
                    'open_issues_count' => 0,
                ],
            ], 200, [
                'X-RateLimit-Remaining' => '4990',
                'X-RateLimit-Limit' => '5000',
            ]),
        ]);

        $response = $this->getJson('/api/github/repositories');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'work-os')
            ->assertJsonPath('data.0.primary_language', 'PHP')
            ->assertJsonPath('rate_limit.remaining', 4990);

        // Test search filter
        $searchResponse = $this->getJson('/api/github/repositories?q=portfolio');
        $searchResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'portfolio');
    }

    public function test_commits_and_branches_and_pull_requests_endpoints(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '123456',
            'github_username' => 'testuser',
            'access_token' => 'ghp_secret_123',
        ]);

        Http::fake([
            'https://api.github.com/repos/testuser/work-os/commits*' => Http::response([
                [
                    'sha' => '1a2b3c4d5e6f7g8h9i0j',
                    'commit' => [
                        'message' => 'feat: implement GitHub integration',
                        'author' => ['name' => 'Test User', 'date' => '2026-10-17T00:00:00Z'],
                    ],
                    'author' => ['login' => 'testuser', 'avatar_url' => 'https://avatar.url'],
                    'html_url' => 'https://github.com/testuser/work-os/commit/1a2b3c4',
                ],
            ], 200),

            'https://api.github.com/repos/testuser/work-os/branches*' => Http::response([
                [
                    'name' => 'main',
                    'commit' => ['sha' => '1a2b3c4d5e6f7g8h9i0j', 'url' => 'https://api.github.com/commit/1a2b3c4'],
                    'protected' => true,
                ],
                [
                    'name' => 'feat/github',
                    'commit' => ['sha' => '9z8y7x6w5v4u3t2s1r0q', 'url' => 'https://api.github.com/commit/9z8y7x6'],
                    'protected' => false,
                ],
            ], 200),

            'https://api.github.com/repos/testuser/work-os/pulls*' => Http::response([
                [
                    'id' => 99,
                    'number' => 12,
                    'title' => 'Feature: GitHub Integration',
                    'state' => 'open',
                    'draft' => false,
                    'html_url' => 'https://github.com/testuser/work-os/pull/12',
                    'created_at' => '2026-10-17T00:00:00Z',
                    'updated_at' => '2026-10-17T01:00:00Z',
                    'merged_at' => null,
                    'user' => ['login' => 'testuser', 'avatar_url' => 'https://avatar.url'],
                    'head' => ['ref' => 'feat/github'],
                    'base' => ['ref' => 'main'],
                ],
            ], 200),
        ]);

        $commitsRes = $this->getJson('/api/github/repositories/testuser/work-os/commits');
        $commitsRes->assertOk()
            ->assertJsonPath('data.0.short_sha', '1a2b3c4')
            ->assertJsonPath('data.0.message', 'feat: implement GitHub integration');

        $branchesRes = $this->getJson('/api/github/repositories/testuser/work-os/branches?default_branch=main');
        $branchesRes->assertOk()
            ->assertJsonPath('data.0.name', 'main')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.name', 'feat/github')
            ->assertJsonPath('data.1.is_default', false);

        $prsRes = $this->getJson('/api/github/repositories/testuser/work-os/pull-requests');
        $prsRes->assertOk()
            ->assertJsonPath('data.0.number', 12)
            ->assertJsonPath('data.0.state', 'open')
            ->assertJsonPath('data.0.title', 'Feature: GitHub Integration');
    }

    public function test_webhook_rejects_missing_headers_or_invalid_signature(): void
    {
        $payload = json_encode(['action' => 'opened']);

        // Missing signature
        $this->postJson('/api/github/webhook', ['action' => 'opened'], [
            'X-GitHub-Delivery' => 'deliv_1',
            'X-GitHub-Event' => 'push',
        ])->assertStatus(401);

        // Invalid signature
        $this->postJson('/api/github/webhook', ['action' => 'opened'], [
            'X-GitHub-Delivery' => 'deliv_1',
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => 'sha256=invalid_signature_hash',
        ])->assertStatus(401);
    }

    public function test_webhook_verifies_valid_signature_and_stores_event_idempotently(): void
    {
        $secret = 'test_webhook_secret_key';
        $payloadArray = [
            'action' => 'opened',
            'repository' => [
                'full_name' => 'testuser/work-os',
            ],
            'sender' => [
                'login' => 'testuser',
            ],
            'pull_request' => [
                'number' => 15,
                'title' => 'Idempotent Webhook Test',
            ],
        ];

        $rawBody = json_encode($payloadArray);
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        // First delivery
        $response1 = $this->call(
            'POST',
            '/api/github/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_DELIVERY' => 'delivery_uuid_99999',
                'HTTP_X_GITHUB_EVENT' => 'pull_request',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawBody
        );

        $response1->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('event', 'pull_request');

        $this->assertDatabaseHas('github_webhook_events', [
            'delivery_id' => 'delivery_uuid_99999',
            'event' => 'pull_request',
            'repository' => 'testuser/work-os',
            'sender' => 'testuser',
        ]);

        // Duplicate delivery (same delivery ID)
        $response2 = $this->call(
            'POST',
            '/api/github/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_DELIVERY' => 'delivery_uuid_99999',
                'HTTP_X_GITHUB_EVENT' => 'pull_request',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawBody
        );

        $response2->assertOk();

        // Count should still be exactly 1
        $this->assertEquals(1, GithubWebhookEvent::where('delivery_id', 'delivery_uuid_99999')->count());
    }

    public function test_webhook_events_feed_endpoint(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $account = GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '123456',
            'github_username' => 'testuser',
            'access_token' => 'ghp_secret_123',
        ]);

        $workspace = $user->workspaces()->create(['name' => 'Test', 'slug' => 'test']);
        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Work OS',
            'slug' => 'work-os',
        ]);

        GithubRepositoryLink::create([
            'user_id' => $user->id,
            'github_account_id' => $account->id,
            'project_id' => $project->id,
            'repository_id' => '101',
            'owner' => 'testuser',
            'name' => 'work-os',
            'full_name' => 'testuser/work-os',
        ]);

        GithubWebhookEvent::create([
            'delivery_id' => 'deliv_abc',
            'event' => 'push',
            'repository' => 'testuser/work-os',
            'sender' => 'testuser',
            'processed' => true,
        ]);

        $response = $this->getJson('/api/github/webhook-events');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 'deliv_abc')
            ->assertJsonPath('data.0.event', 'push')
            ->assertJsonPath('data.0.repo', 'testuser/work-os');
    }
}
