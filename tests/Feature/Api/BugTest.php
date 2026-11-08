<?php

namespace Tests\Feature\Api;

use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BugTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user, string $name = 'Alpha'): Project
    {
        $workspace = $user->workspaces()->firstOrCreate(
            ['slug' => 'test-workspace'],
            ['name' => 'Test Workspace'],
        );

        return $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
        ]);
    }

    public function test_user_can_create_list_search_and_filter_bugs(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/testing/bugs', [
            'project_id' => $project->id,
            'title' => 'Checkout crashes on mobile',
            'details' => 'Crash after tapping pay.',
            'priority' => 'critical',
            'status' => 'open',
            'testing_notes' => 'Reproduced on iOS.',
        ])->assertCreated()
            ->assertJsonPath('data.bug_id', 'BUG-0001')
            ->assertJsonPath('data.priority', 'critical')
            ->json('data');

        $this->postJson('/api/testing/bugs', [
            'project_id' => $project->id,
            'title' => 'Typo in footer',
            'priority' => 'low',
            'status' => 'fixed',
        ])->assertCreated()
            ->assertJsonPath('data.bug_id', 'BUG-0002');

        $this->assertSame($project->id, $first['project_id']);

        $this->getJson('/api/testing/bugs?search=checkout')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Checkout crashes on mobile');

        $this->getJson('/api/testing/bugs?status=fixed&priority=low')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Typo in footer');

        $this->getJson('/api/testing/bugs?sort=priority&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.priority', 'critical');

        $this->getJson('/api/testing/bugs?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_bug_statistics_are_computed_from_visible_records(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        Sanctum::actingAs($user);

        Bug::create([
            'project_id' => $project->id,
            'number' => 1,
            'title' => 'One',
            'priority' => 'critical',
            'status' => 'open',
            'created_by' => $user->id,
        ]);
        Bug::create([
            'project_id' => $project->id,
            'number' => 2,
            'title' => 'Two',
            'priority' => 'medium',
            'status' => 'fixed',
            'validated' => true,
            'created_by' => $user->id,
        ]);

        $this->getJson('/api/testing/bugs/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.by_status.open', 1)
            ->assertJsonPath('data.by_status.fixed', 1)
            ->assertJsonPath('data.by_priority.critical', 1)
            ->assertJsonPath('data.validated', 1)
            ->assertJsonPath('data.unvalidated', 1);
    }

    public function test_bug_update_validation_and_delete(): void
    {
        $user = User::factory()->create();
        $project = $this->projectFor($user);
        Sanctum::actingAs($user);

        $bugId = $this->postJson('/api/testing/bugs', [
            'project_id' => $project->id,
            'title' => 'Original',
        ])->json('data.id');

        $this->patchJson('/api/testing/bugs/'.$bugId, [
            'status' => 'retesting',
            'validated' => true,
            'testing_notes' => 'Retest scheduled.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'retesting')
            ->assertJsonPath('data.validated', true);

        $this->patchJson('/api/testing/bugs/'.$bugId, ['priority' => 'not-a-priority'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('priority');

        $this->deleteJson('/api/testing/bugs/'.$bugId)->assertNoContent();
        $this->assertDatabaseMissing('bugs', ['id' => $bugId]);
    }

    public function test_users_cannot_read_or_modify_other_users_bugs(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $project = $this->projectFor($owner);
        $bug = Bug::create([
            'project_id' => $project->id,
            'number' => 1,
            'title' => 'Private',
            'created_by' => $owner->id,
        ]);

        Sanctum::actingAs($outsider);

        $this->getJson('/api/testing/bugs')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/testing/bugs/'.$bug->id)->assertForbidden();
        $this->patchJson('/api/testing/bugs/'.$bug->id, ['title' => 'Hijack'])->assertForbidden();
        $this->postJson('/api/testing/bugs', [
            'project_id' => $project->id,
            'title' => 'Not mine',
        ])->assertForbidden();
    }
}
