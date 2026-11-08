<?php

namespace Tests\Feature\Api;

use App\Models\Bug;
use App\Models\GithubAccount;
use App\Models\GithubLink;
use App\Models\GithubRepositoryLink;
use App\Models\GithubWebhookEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GithubLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.github.client_id', 'test_client');
        Config::set('services.github.client_secret', 'test_secret');
        Config::set('services.github.webhook_secret', 'webhook-secret');
        Config::set('services.github.frontend_url', 'http://localhost:5173');
    }

    private function account(User $user): GithubAccount
    {
        return GithubAccount::create([
            'user_id' => $user->id,
            'github_user_id' => '99',
            'github_username' => 'octo',
            'access_token' => 'ghp_token',
        ]);
    }

    private function projectFor(User $user): Project
    {
        $workspace = $user->workspaces()->firstOrCreate(
            ['slug' => 'workspace'],
            ['name' => 'Workspace'],
        );

        return $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Alpha',
            'slug' => 'alpha-'.uniqid(),
        ]);
    }

    private function repositoryLink(User $user, Project $project): GithubRepositoryLink
    {
        return GithubRepositoryLink::create([
            'user_id' => $user->id,
            'github_account_id' => $this->account($user)->id,
            'project_id' => $project->id,
            'repository_id' => '101',
            'owner' => 'octo',
            'name' => 'work-os',
            'full_name' => 'octo/work-os',
            'default_branch' => 'main',
            'sync_issues' => true,
            'sync_pull_requests' => true,
            'auto_sync' => true,
        ]);
    }

    public function test_user_can_link_an_authorized_repository_to_a_project(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $this->account($user);
        Sanctum::actingAs($user);

        Http::fake([
            'https://api.github.com/repos/octo/work-os' => Http::response([
                'id' => 101,
                'name' => 'work-os',
                'full_name' => 'octo/work-os',
                'owner' => ['login' => 'octo'],
                'default_branch' => 'main',
                'private' => true,
                'html_url' => 'https://github.com/octo/work-os',
            ], 200),
        ]);

        $this->postJson('/api/github/links', [
            'project_id' => $project->id,
            'owner' => 'octo',
            'repo' => 'work-os',
        ])->assertCreated()
            ->assertJsonPath('data.full_name', 'octo/work-os')
            ->assertJsonPath('data.project.id', $project->id);

        $this->assertDatabaseHas('github_repository_links', [
            'project_id' => $project->id,
            'repository_id' => '101',
        ]);

        $this->postJson('/api/github/links', [
            'project_id' => $project->id,
            'owner' => 'octo',
            'repo' => 'work-os',
        ])->assertUnprocessable();
    }

    public function test_issues_are_listed_and_can_be_associated_with_a_bug(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $link = $this->repositoryLink($user, $project);
        $bug = Bug::create([
            'project_id' => $project->id,
            'number' => 1,
            'title' => 'Login issue',
            'created_by' => $user->id,
        ]);
        Sanctum::actingAs($user);

        Http::fake([
            'https://api.github.com/repos/octo/work-os/issues*' => Http::response([
                [
                    'id' => 1,
                    'number' => 7,
                    'title' => 'Login fails on Safari',
                    'state' => 'open',
                    'body' => 'Steps...',
                    'html_url' => 'https://github.com/octo/work-os/issues/7',
                    'created_at' => '2026-10-20T10:00:00Z',
                    'updated_at' => '2026-10-20T10:00:00Z',
                    'user' => ['login' => 'octo'],
                    'labels' => [['name' => 'bug']],
                ],
                [
                    'id' => 2,
                    'number' => 8,
                    'title' => 'A pull request',
                    'state' => 'open',
                    'pull_request' => ['url' => 'https://api.github.com/pulls/8'],
                ],
            ], 200),
        ]);

        $this->getJson('/api/github/links/'.$link->id.'/issues')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 7);

        $response = $this->postJson('/api/github/links/'.$link->id.'/items', [
            'github_type' => 'issue',
            'github_number' => 7,
            'title' => 'Login fails on Safari',
            'state' => 'open',
            'html_url' => 'https://github.com/octo/work-os/issues/7',
            'local_type' => 'bug',
            'local_id' => $bug->id,
        ])->assertCreated();

        $githubLinkId = $response->json('data.id');

        $this->postJson('/api/github/links/'.$link->id.'/items', [
            'github_type' => 'issue',
            'github_number' => 7,
            'title' => 'Login fails on Safari',
            'state' => 'open',
            'html_url' => 'https://github.com/octo/work-os/issues/7',
            'local_type' => 'bug',
            'local_id' => $bug->id,
        ])->assertCreated();

        $this->assertSame(1, GithubLink::count());

        $otherBug = Bug::create([
            'project_id' => $project->id,
            'number' => 2,
            'title' => 'Other',
            'created_by' => $user->id,
        ]);

        $this->postJson('/api/github/links/'.$link->id.'/items', [
            'github_type' => 'issue',
            'github_number' => 7,
            'title' => 'Login fails on Safari',
            'state' => 'open',
            'html_url' => 'https://github.com/octo/work-os/issues/7',
            'local_type' => 'bug',
            'local_id' => $otherBug->id,
        ])->assertUnprocessable();

        $this->deleteJson('/api/github/links/'.$link->id.'/items/'.$githubLinkId)->assertNoContent();
        $this->assertSame(0, GithubLink::count());
    }

    public function test_linked_items_are_private_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);
        $link = $this->repositoryLink($owner, $project);

        GithubLink::create([
            'github_repository_link_id' => $link->id,
            'user_id' => $owner->id,
            'github_type' => 'issue',
            'github_number' => 7,
            'title' => 'Private issue link',
            'state' => 'open',
            'local_type' => 'bug',
            'local_id' => 1,
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/github/linked-items')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/github/linked-items')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_cannot_associate_an_issue_with_a_record_from_another_project(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);
        $otherProject = $this->projectFor($owner);
        $link = $this->repositoryLink($owner, $project);

        $foreignBug = Bug::create([
            'project_id' => $otherProject->id,
            'number' => 1,
            'title' => 'Foreign bug',
            'created_by' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/github/links/'.$link->id.'/items', [
            'github_type' => 'issue',
            'github_number' => 7,
            'title' => 'Login fails on Safari',
            'state' => 'open',
            'local_type' => 'bug',
            'local_id' => $foreignBug->id,
        ])->assertNotFound();

        $this->assertSame(0, GithubLink::count());
    }

    public function test_signed_webhook_updates_linked_bug_and_task(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        $link = $this->repositoryLink($user, $project);

        $bug = Bug::create([
            'project_id' => $project->id,
            'number' => 1,
            'title' => 'Login issue',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'workspace_id' => $project->workspace_id,
            'title' => 'Fix login',
            'status' => 'in_progress',
        ]);

        GithubLink::create([
            'github_repository_link_id' => $link->id,
            'user_id' => $user->id,
            'github_type' => 'issue',
            'github_number' => 7,
            'title' => 'Login fails',
            'state' => 'open',
            'local_type' => 'bug',
            'local_id' => $bug->id,
        ]);

        GithubLink::create([
            'github_repository_link_id' => $link->id,
            'user_id' => $user->id,
            'github_type' => 'pull_request',
            'github_number' => 9,
            'title' => 'Fix login form',
            'state' => 'open',
            'local_type' => 'task',
            'local_id' => $task->id,
        ]);

        $issuePayload = [
            'action' => 'closed',
            'repository' => ['id' => 101, 'full_name' => 'octo/work-os'],
            'issue' => [
                'number' => 7,
                'title' => 'Login fails',
                'state' => 'closed',
                'html_url' => 'https://github.com/octo/work-os/issues/7',
            ],
        ];

        $this->signedWebhook('delivery-1', 'issues', $issuePayload)->assertOk();

        $this->assertSame('fixed', $bug->fresh()->status->value);
        $this->assertSame('closed', GithubLink::where('github_type', 'issue')->first()->state);

        $prPayload = [
            'action' => 'closed',
            'repository' => ['id' => 101, 'full_name' => 'octo/work-os'],
            'pull_request' => [
                'number' => 9,
                'title' => 'Fix login form',
                'state' => 'closed',
                'merged_at' => '2026-10-21T10:00:00Z',
                'html_url' => 'https://github.com/octo/work-os/pull/9',
            ],
        ];

        $this->signedWebhook('delivery-2', 'pull_request', $prPayload)->assertOk();

        $this->assertSame('review', $task->fresh()->status->value);
        $this->assertSame('merged', GithubLink::where('github_type', 'pull_request')->first()->state);

        // Duplicate delivery must not create duplicate records.
        $this->signedWebhook('delivery-2', 'pull_request', $prPayload)->assertOk();
        $this->assertSame(1, GithubWebhookEvent::where('delivery_id', 'delivery-2')->count());
    }

    public function test_webhook_events_are_only_visible_for_linked_repositories(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner);
        $this->repositoryLink($owner, $project);

        GithubWebhookEvent::create([
            'delivery_id' => 'visible',
            'event' => 'issues',
            'repository' => 'octo/work-os',
            'processed' => true,
        ]);
        GithubWebhookEvent::create([
            'delivery_id' => 'hidden',
            'event' => 'push',
            'repository' => 'someone/else',
            'processed' => true,
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/github/webhook-events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'visible');

        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->getJson('/api/github/webhook-events')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_users_cannot_use_or_modify_a_repository_link(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $project = $this->projectFor($owner);
        $link = $this->repositoryLink($owner, $project);

        Sanctum::actingAs($outsider);

        $this->getJson('/api/github/links/'.$link->id.'/issues')->assertForbidden();
        $this->patchJson('/api/github/links/'.$link->id, ['sync_issues' => false])->assertForbidden();
        $this->deleteJson('/api/github/links/'.$link->id)->assertForbidden();
    }

    private function signedWebhook(string $delivery, string $event, array $payload)
    {
        $raw = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'webhook-secret');

        return $this->call(
            'POST',
            '/api/github/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITHUB_DELIVERY' => $delivery,
                'HTTP_X_GITHUB_EVENT' => $event,
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $raw,
        );
    }
}
