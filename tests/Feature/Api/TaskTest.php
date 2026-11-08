<?php

namespace Tests\Feature\Api;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $project->id,
            'title' => 'Investigate API error',
        ], $overrides);
    }

    public function test_task_endpoints_require_authentication(): void
    {
        $task = Task::factory()->create();
        $dependency = Task::factory()->create();

        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->postJson('/api/tasks', ['title' => 'x'])->assertUnauthorized();
        $this->getJson('/api/tags')->assertUnauthorized();

        $this->postJson("/api/tasks/{$task->id}/dependencies", ['depends_on_task_id' => $dependency->id])
            ->assertUnauthorized();
        $this->deleteJson("/api/tasks/{$task->id}/dependencies/{$dependency->id}")
            ->assertUnauthorized();
    }

    public function test_user_can_create_a_task(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $response = $this->postJson('/api/tasks', $this->payload($project, [
            'description' => 'Check the logs',
            'priority' => 'high',
            'due_at' => now()->addDay()->toDateTimeString(),
            'tags' => ['backend', 'urgent'],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Investigate API error')
            ->assertJsonPath('data.status', 'backlog')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.is_blocked', false)
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.project_id', $project->id);

        // Unassigned requests default to the caller.
        $this->assertSame($user->id, $response->json('data.assignee.id'));
        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'user_id' => $user->id]);
        $this->assertDatabaseCount('tags', 2);
        $this->assertDatabaseCount('tag_task', 2);
    }

    public function test_task_creation_is_validated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['project_id', 'title']);

        $this->postJson('/api/tasks', $this->payload($project, ['status' => 'bogus']))
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->postJson('/api/tasks', $this->payload($project, ['priority' => 'bogus']))
            ->assertStatus(422)->assertJsonValidationErrors('priority');

        $this->postJson('/api/tasks', $this->payload($project, ['project_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors('project_id');
    }

    public function test_tasks_cannot_be_created_in_another_users_project(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/tasks', $this->payload($project))->assertForbidden();
    }

    public function test_assignee_must_be_the_caller_or_null(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', $this->payload($project, ['assignee_id' => $other->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('assignee_id');

        $this->postJson('/api/tasks', $this->payload($project, ['assignee_id' => null]))
            ->assertCreated()
            ->assertJsonPath('data.assignee', null);
    }

    public function test_subtasks_are_supported_and_counted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();
        $parent = Task::factory()->forProject($project)->create(['title' => 'Parent']);

        $this->postJson('/api/tasks', $this->payload($project, ['title' => 'Child', 'parent_id' => $parent->id]))
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id);

        $this->getJson("/api/tasks/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('data.subtasks_count', 1);
    }

    public function test_parent_must_belong_to_the_same_project(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $projectA = Project::factory()->for($user)->create();
        $projectB = Project::factory()->for($user)->create();
        $foreign = Task::factory()->forProject($projectB)->create();

        $this->postJson('/api/tasks', $this->payload($projectA, ['parent_id' => $foreign->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_task_cannot_be_its_own_ancestor(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();
        $parent = Task::factory()->forProject($project)->create();
        $child = Task::factory()->forProject($project)->create(['parent_id' => $parent->id]);

        $this->patchJson("/api/tasks/{$parent->id}", ['parent_id' => $parent->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');

        // Making the parent a child of its own child would create a loop.
        $this->patchJson("/api/tasks/{$parent->id}", ['parent_id' => $child->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_completing_a_task_sets_completed_at_and_reopening_clears_it(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();
        $task = Task::factory()->forProject($project)->create();

        $this->assertNull($task->completed_at);

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])->assertOk();
        $this->assertNotNull($task->refresh()->completed_at);

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'in_progress'])->assertOk();
        $this->assertNull($task->refresh()->completed_at);
    }

    public function test_a_task_cannot_be_moved_between_projects(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();
        $other = Project::factory()->for($user)->create();
        $task = Task::factory()->forProject($project)->create();

        $this->patchJson("/api/tasks/{$task->id}", ['project_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_id');
    }

    public function test_tags_are_reused_across_tasks_and_replaced_on_update(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', $this->payload($project, ['tags' => ['Backend', 'api']]))->assertCreated();
        $this->assertDatabaseCount('tags', 2);

        // A differently-cased duplicate reuses the existing tag row.
        $second = $this->postJson('/api/tasks', $this->payload($project, ['tags' => ['backend']]))
            ->assertCreated()
            ->json('data.id');
        $this->assertDatabaseCount('tags', 2);
        $this->assertDatabaseCount('tag_task', 3);

        // An explicit empty array clears the tag set.
        $this->patchJson("/api/tasks/{$second}", ['tags' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.tags');
        $this->assertDatabaseCount('tag_task', 2);
    }

    public function test_tag_index_returns_only_the_callers_tags(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', $this->payload($project, ['tags' => ['alpha']]))->assertCreated();
        Tag::factory()->create();

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'alpha');
    }

    public function test_index_only_returns_tasks_from_the_callers_projects(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        Task::factory()->forProject($project)->create(['title' => 'Mine']);
        Task::factory()->create(['title' => 'Theirs']);

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine');
    }

    public function test_index_filters(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        Task::factory()->forProject($project)->create([
            'title' => 'Alpha', 'description' => 'first', 'status' => TaskStatus::Todo, 'priority' => TaskPriority::High,
        ]);
        Task::factory()->forProject($project)->create([
            'title' => 'Beta', 'description' => 'second', 'status' => TaskStatus::Done, 'priority' => TaskPriority::Low,
        ]);

        $this->getJson('/api/tasks?status=todo')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Alpha');

        $this->getJson('/api/tasks?priority=high')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Alpha');

        $this->getJson("/api/tasks?project_id={$project->id}")
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/tasks?q=Beta')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Beta');

        $this->getJson('/api/tasks?top_level=1')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/tasks?assignee_id={$user->id}")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/tasks?status=bogus')->assertStatus(422);
    }

    public function test_overdue_filter_returns_only_unfinished_past_due_tasks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        Task::factory()->forProject($project)->create(['title' => 'Late', 'due_at' => now()->subDay()]);
        Task::factory()->forProject($project)->create(['title' => 'Future', 'due_at' => now()->addDay()]);
        Task::factory()->forProject($project)->done()->create(['title' => 'Late done', 'due_at' => now()->subDay()]);

        $this->getJson('/api/tasks?overdue=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Late');
    }

    public function test_index_sorts_by_priority_weight_not_alphabetically(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        Task::factory()->forProject($project)->priority(TaskPriority::Low)->create(['title' => 'Low']);
        Task::factory()->forProject($project)->priority(TaskPriority::Urgent)->create(['title' => 'Urgent']);
        Task::factory()->forProject($project)->priority(TaskPriority::High)->create(['title' => 'High']);

        $response = $this->getJson('/api/tasks?sort=priority')->assertOk();

        $this->assertSame(
            ['Urgent', 'High', 'Low'],
            array_column($response->json('data'), 'title'),
        );
    }

    public function test_task_authorization_is_enforced(): void
    {
        $task = Task::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/tasks/{$task->id}")->assertForbidden();
        $this->patchJson("/api/tasks/{$task->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/tasks/{$task->id}")->assertForbidden();

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_deleting_a_task_also_removes_its_subtasks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();
        $parent = Task::factory()->forProject($project)->create();
        $child = Task::factory()->forProject($project)->create(['parent_id' => $parent->id]);

        $this->deleteJson("/api/tasks/{$parent->id}")->assertNoContent();

        $this->assertDatabaseMissing('tasks', ['id' => $parent->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $child->id]);
    }

    public function test_overdue_is_reported_for_unfinished_past_due_tasks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $overdue = Task::factory()->forProject($project)->create([
            'title' => 'Late', 'due_at' => now()->subDay(),
        ]);
        $finished = Task::factory()->forProject($project)->done()->create([
            'title' => 'Late but done', 'due_at' => now()->subDay(),
        ]);

        $this->getJson("/api/tasks/{$overdue->id}")->assertOk()->assertJsonPath('data.is_overdue', true);
        $this->getJson("/api/tasks/{$finished->id}")->assertOk()->assertJsonPath('data.is_overdue', false);
    }
}
