<?php

namespace Tests\Feature\Api;

use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TesterAccessTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user, string $name): Project
    {
        $workspace = $user->workspaces()->firstOrCreate(
            ['slug' => 'workspace'],
            ['name' => 'Workspace'],
        );

        return $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
        ]);
    }

    /**
     * @return array{invitation_id: int, access_key: string}
     */
    private function invite(User $owner, Project $project, string $email = 'tester@example.com'): array
    {
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/testing/invitations', [
            'name' => 'Tess Tester',
            'email' => $email,
            'project_ids' => [$project->id],
        ])->assertCreated();

        return [
            'invitation_id' => $response->json('data.id'),
            'access_key' => $response->json('access_key'),
        ];
    }

    private function loginTesterToken(string $email, string $key): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/tester-login', [
            'email' => $email,
            'access_key' => $key,
        ])->assertOk()->json('data.token');
    }

    private function tester(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->json($method, $uri, $data, [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    public function test_owner_invites_tester_who_can_only_reach_authorized_testing_data(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner, 'Alpha');
        $restrictedProject = $this->projectFor($owner, 'Beta');

        $invite = $this->invite($owner, $project);
        $this->assertNotEmpty($invite['access_key']);
        $this->assertStringStartsWith('tst_', $invite['access_key']);
        $this->assertDatabaseMissing('tester_invitations', ['key_hash' => $invite['access_key']]);

        $token = $this->loginTesterToken('tester@example.com', $invite['access_key']);

        $this->tester($token, 'GET', '/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.role', 'tester');

        $this->tester($token, 'GET', '/api/testing/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);

        $this->tester($token, 'GET', '/api/projects')->assertForbidden();
        $this->tester($token, 'GET', '/api/dashboard')->assertForbidden();
        $this->tester($token, 'GET', '/api/work-reports')->assertForbidden();
        $this->tester($token, 'GET', '/api/testing/invitations')->assertForbidden();

        $bugId = $this->tester($token, 'POST', '/api/testing/bugs', [
            'project_id' => $project->id,
            'title' => 'Tester reported bug',
            'priority' => 'high',
        ])->assertCreated()
            ->assertJsonPath('data.created_by.name', 'Tess Tester')
            ->json('data.id');

        $this->tester($token, 'GET', '/api/testing/bugs/'.$bugId)->assertOk();

        $this->tester($token, 'POST', '/api/testing/bugs', [
            'project_id' => $restrictedProject->id,
            'title' => 'Unauthorized project bug',
        ])->assertForbidden();

        Bug::create([
            'project_id' => $restrictedProject->id,
            'number' => 1,
            'title' => 'Other project bug',
            'created_by' => $owner->id,
        ]);

        $this->tester($token, 'GET', '/api/testing/bugs')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_invalid_tester_key_is_rejected(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner, 'Alpha');
        $this->invite($owner, $project);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/tester-login', [
            'email' => 'tester@example.com',
            'access_key' => 'tst_wrong-key',
        ])->assertUnprocessable()->assertJsonValidationErrors('access_key');
    }

    public function test_revoking_access_invalidates_tokens_and_future_logins(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner, 'Alpha');
        $invite = $this->invite($owner, $project);
        $token = $this->loginTesterToken('tester@example.com', $invite['access_key']);

        $this->tester($token, 'GET', '/api/testing/bugs')->assertOk();

        Sanctum::actingAs($owner);
        $this->postJson('/api/testing/invitations/'.$invite['invitation_id'].'/revoke')
            ->assertNoContent();

        $this->app['auth']->forgetGuards();
        // Revocation deletes the tester's tokens, so the old bearer token is
        // rejected by authentication before authorization runs.
        $this->tester($token, 'GET', '/api/testing/bugs')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/tester-login', [
            'email' => 'tester@example.com',
            'access_key' => $invite['access_key'],
        ])->assertUnprocessable();
    }

    public function test_rotating_the_key_revokes_sessions_and_old_key(): void
    {
        $owner = User::factory()->create();
        $project = $this->projectFor($owner, 'Alpha');
        $invite = $this->invite($owner, $project);
        $token = $this->loginTesterToken('tester@example.com', $invite['access_key']);

        Sanctum::actingAs($owner);
        $newKey = $this->postJson('/api/testing/invitations/'.$invite['invitation_id'].'/rotate-key')
            ->assertOk()
            ->json('access_key');

        $this->assertNotSame($invite['access_key'], $newKey);

        $this->app['auth']->forgetGuards();
        $this->tester($token, 'GET', '/api/testing/bugs')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/tester-login', [
            'email' => 'tester@example.com',
            'access_key' => $invite['access_key'],
        ])->assertUnprocessable();

        $this->loginTesterToken('tester@example.com', $newKey);
    }

    public function test_only_the_inviting_owner_can_manage_invitations(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $project = $this->projectFor($owner, 'Alpha');
        $invite = $this->invite($owner, $project);

        Sanctum::actingAs($outsider);
        $this->patchJson('/api/testing/invitations/'.$invite['invitation_id'], ['name' => 'Nope'])
            ->assertForbidden();

        $this->postJson('/api/testing/invitations/'.$invite['invitation_id'].'/revoke')
            ->assertForbidden();

        $this->postJson('/api/testing/invitations', [
            'name' => 'Outsider tester',
            'email' => 'other@example.com',
            'project_ids' => [$project->id],
        ])->assertUnprocessable();
    }
}
