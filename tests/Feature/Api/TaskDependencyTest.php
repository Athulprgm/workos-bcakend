<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskDependencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user)->create();
        Sanctum::actingAs($this->user);
    }

    private function task(string $title): Task
    {
        return Task::factory()->forProject($this->project)->create(['title' => $title]);
    }

    private function addDependency(Task $task, Task $dependency)
    {
        return $this->postJson("/api/tasks/{$task->id}/dependencies", [
            'depends_on_task_id' => $dependency->id,
        ]);
    }

    public function test_adding_a_dependency_marks_the_task_as_blocked(): void
    {
        $task = $this->task('Blocked');
        $dependency = $this->task('Blocker');

        $this->addDependency($task, $dependency)
            ->assertCreated()
            ->assertJsonPath('data.is_blocked', true)
            ->assertJsonPath('data.blocked_by.0.id', $dependency->id)
            ->assertJsonPath('data.blocked_by.0.title', 'Blocker');

        $this->assertDatabaseHas('task_dependencies', [
            'task_id' => $task->id,
            'depends_on_task_id' => $dependency->id,
        ]);
    }

    public function test_a_duplicate_dependency_returns_a_conflict(): void
    {
        $task = $this->task('Blocked');
        $dependency = $this->task('Blocker');

        $this->addDependency($task, $dependency)->assertCreated();
        $this->addDependency($task, $dependency)->assertStatus(409);

        $this->assertDatabaseCount('task_dependencies', 1);
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        $task = $this->task('Solo');

        $this->addDependency($task, $task)
            ->assertStatus(422)
            ->assertJsonValidationErrors('depends_on_task_id');
    }

    public function test_a_dependency_must_be_in_the_same_project(): void
    {
        $task = $this->task('Blocked');
        $foreign = Task::factory()->create();

        $this->addDependency($task, $foreign)
            ->assertStatus(422)
            ->assertJsonValidationErrors('depends_on_task_id');
    }

    public function test_a_direct_cycle_is_rejected(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');

        $this->addDependency($a, $b)->assertCreated();

        // B waiting on A would deadlock both.
        $this->addDependency($b, $a)
            ->assertStatus(422)
            ->assertJsonValidationErrors('depends_on_task_id');
    }

    public function test_a_transitive_cycle_is_rejected(): void
    {
        $a = $this->task('A');
        $b = $this->task('B');
        $c = $this->task('C');

        $this->addDependency($a, $b)->assertCreated();
        $this->addDependency($b, $c)->assertCreated();

        // A -> B -> C, so C waiting on A closes the loop.
        $this->addDependency($c, $a)
            ->assertStatus(422)
            ->assertJsonValidationErrors('depends_on_task_id');
    }

    public function test_finishing_a_dependency_unblocks_the_task(): void
    {
        $task = $this->task('Blocked');
        $dependency = $this->task('Blocker');

        $this->addDependency($task, $dependency)->assertCreated();

        $this->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.is_blocked', true);

        $this->patchJson("/api/tasks/{$dependency->id}", ['status' => 'done'])->assertOk();

        $this->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.is_blocked', false);
    }

    public function test_blocked_filter_selects_blocked_and_unblocked_tasks(): void
    {
        $blocked = $this->task('Blocked');
        $free = $this->task('Free');
        $dependency = $this->task('Blocker');

        $this->addDependency($blocked, $dependency)->assertCreated();

        $this->getJson('/api/tasks?blocked=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Blocked');

        $this->getJson('/api/tasks?blocked=0')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_dependency_can_be_removed(): void
    {
        $task = $this->task('Blocked');
        $dependency = $this->task('Blocker');

        $this->addDependency($task, $dependency)->assertCreated();

        $this->deleteJson("/api/tasks/{$task->id}/dependencies/{$dependency->id}")
            ->assertOk()
            ->assertJsonPath('data.is_blocked', false)
            ->assertJsonCount(0, 'data.blocked_by');

        $this->assertDatabaseCount('task_dependencies', 0);
    }

    public function test_dependencies_cannot_be_changed_on_another_users_task(): void
    {
        $task = $this->task('Mine');
        $dependency = $this->task('Blocker');

        // A second user authenticated for the same request.
        Sanctum::actingAs(User::factory()->create());

        $this->addDependency($task, $dependency)->assertForbidden();
        $this->deleteJson("/api/tasks/{$task->id}/dependencies/{$dependency->id}")->assertForbidden();
    }
}
