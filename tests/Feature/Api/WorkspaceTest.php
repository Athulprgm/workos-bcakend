<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_endpoints_require_authentication(): void
    {
        $this->getJson('/api/workspaces')->assertUnauthorized();
        $this->postJson('/api/workspaces', ['name' => 'Acme Corp'])->assertUnauthorized();
    }

    public function test_user_gets_default_multi_company_workspaces(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/workspaces')->assertOk();
        $workspaces = $response->json('data');

        $this->assertCount(3, $workspaces);
        $names = collect($workspaces)->pluck('name')->all();
        $this->assertContains('External Company', $names);
        $this->assertContains('My Company', $names);
        $this->assertContains('Personal Workspace', $names);
    }

    public function test_user_can_create_custom_workspace(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/workspaces', [
            'name' => 'Starlight Digital',
            'company_type' => 'client',
            'color' => '#10B981',
            'settings' => ['contract' => 'Retainer'],
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'Starlight Digital')
            ->assertJsonPath('data.slug', 'starlight-digital')
            ->assertJsonPath('data.company_type', 'client')
            ->assertJsonPath('data.color', '#10B981');

        $this->assertDatabaseHas('workspaces', [
            'user_id' => $user->id,
            'name' => 'Starlight Digital',
        ]);
    }

    public function test_user_cannot_access_or_modify_other_users_workspace(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $workspaceA = $userA->workspaces()->create([
            'name' => 'Private Corp A',
            'slug' => 'private-corp-a',
            'company_type' => 'company',
        ]);

        Sanctum::actingAs($userB);

        $this->getJson("/api/workspaces/{$workspaceA->id}")->assertForbidden();
        $this->patchJson("/api/workspaces/{$workspaceA->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/workspaces/{$workspaceA->id}")->assertForbidden();
    }

    public function test_workspace_isolation_for_projects_and_tasks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wsExternal = $user->workspaces()->create([
            'name' => 'External Company',
            'slug' => 'external-company',
            'company_type' => 'external',
        ]);

        $wsMine = $user->workspaces()->create([
            'name' => 'My Company',
            'slug' => 'my-company',
            'company_type' => 'my_company',
        ]);

        $projExternal = $user->projects()->create([
            'workspace_id' => $wsExternal->id,
            'name' => 'External Bank Integration',
            'slug' => 'external-bank',
        ]);

        $projMine = $user->projects()->create([
            'workspace_id' => $wsMine->id,
            'name' => 'My SaaS Product',
            'slug' => 'my-saas',
        ]);

        // When filtering by External Company workspace
        $resExternal = $this->getJson("/api/projects?workspace_id={$wsExternal->id}")->assertOk();
        $externalNames = collect($resExternal->json('data'))->pluck('name')->all();
        $this->assertContains('External Bank Integration', $externalNames);
        $this->assertNotContains('My SaaS Product', $externalNames);

        // When filtering by My Company workspace
        $resMine = $this->getJson("/api/projects?workspace_id={$wsMine->id}")->assertOk();
        $mineNames = collect($resMine->json('data'))->pluck('name')->all();
        $this->assertContains('My SaaS Product', $mineNames);
        $this->assertNotContains('External Bank Integration', $mineNames);

        // Header based isolation
        $resHeader = $this->withHeaders(['X-Workspace-Id' => $wsExternal->id])
            ->getJson('/api/projects')->assertOk();
        $headerNames = collect($resHeader->json('data'))->pluck('name')->all();
        $this->assertContains('External Bank Integration', $headerNames);
        $this->assertNotContains('My SaaS Product', $headerNames);
    }
}
