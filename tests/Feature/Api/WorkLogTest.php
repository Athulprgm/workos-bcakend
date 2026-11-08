<?php

namespace Tests\Feature\Api;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_work_log_without_auto_completing_task(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Tech Corp',
            'slug' => 'tech-corp',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Backend Refactor',
            'slug' => 'backend-refactor',
        ]);

        $task = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Optimize queries',
            'status' => TaskStatus::InProgress->value,
        ]);

        $response = $this->postJson('/api/work-logs', [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'work_description' => 'Profiled database queries and reduced N+1 joins',
            'time_spent_minutes' => 90,
            'problems_encountered' => 'Deadlock on test environment',
            'next_steps' => 'Apply index to task_dependencies',
        ])->assertCreated();

        $response->assertJsonPath('data.work_description', 'Profiled database queries and reduced N+1 joins')
            ->assertJsonPath('data.time_spent_minutes', 90);

        // Verify task status was NOT automatically completed
        $task->refresh();
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_work_logs_scoped_by_workspace(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wsA = $user->workspaces()->create(['name' => 'WS A', 'slug' => 'ws-a']);
        $wsB = $user->workspaces()->create(['name' => 'WS B', 'slug' => 'ws-b']);

        $user->workLogs()->create([
            'workspace_id' => $wsA->id,
            'work_description' => 'Work for Client A',
            'date' => today(),
            'time_spent_minutes' => 60,
        ]);

        $user->workLogs()->create([
            'workspace_id' => $wsB->id,
            'work_description' => 'Work for Client B',
            'date' => today(),
            'time_spent_minutes' => 120,
        ]);

        $resA = $this->getJson("/api/work-logs?workspace_id={$wsA->id}")->assertOk();
        $this->assertCount(1, $resA->json('data'));
        $this->assertSame('Work for Client A', $resA->json('data.0.work_description'));

        // Stats calculation
        $statsA = $this->getJson("/api/work-logs/stats?workspace_id={$wsA->id}")->assertOk();
        $this->assertSame(60, $statsA->json('data.today_minutes'));
    }
}
