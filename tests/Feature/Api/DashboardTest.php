<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_zeroed_aggregates_for_a_new_user(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 0)
            ->assertJsonPath('data.tasks.total', 0)
            ->assertJsonPath('data.tasks.open', 0)
            ->assertJsonPath('data.tasks.blocked', 0)
            ->assertJsonPath('data.tasks.overdue', 0)
            ->assertJsonCount(0, 'data.priority_tasks')
            ->assertJsonCount(0, 'data.recent_projects');
    }

    public function test_aggregates_count_only_the_callers_records(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $open = Task::factory()->forProject($project)->create(['title' => 'Open']);
        Task::factory()->forProject($project)->done()->create(['title' => 'Finished']);
        Task::factory()->forProject($project)->create(['title' => 'Late', 'due_at' => now()->subDay()]);
        $blocker = Task::factory()->forProject($project)->create(['title' => 'Blocker']);
        $open->dependencies()->attach($blocker->id);

        // Another user's records must not leak into the counts.
        Task::factory()->create();
        Project::factory()->create();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 1)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonPath('data.tasks.total', 4)
            ->assertJsonPath('data.tasks.open', 3)
            ->assertJsonPath('data.tasks.done', 1)
            ->assertJsonPath('data.tasks.blocked', 1)
            ->assertJsonPath('data.tasks.overdue', 1);
    }

    public function test_archived_projects_count_towards_the_total_but_not_the_active_count(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Project::factory()->for($user)->create(['status' => ProjectStatus::Active]);
        Project::factory()->for($user)->archived()->create();

        // Pins the intended semantics: recent_projects must not inherit the
        // status filter used to compute the active count.
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.projects.total', 2)
            ->assertJsonPath('data.projects.active', 1)
            ->assertJsonCount(2, 'data.recent_projects');
    }

    public function test_priority_tasks_are_ordered_by_weight_and_exclude_finished_work(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        Task::factory()->forProject($project)->priority(TaskPriority::Low)->create(['title' => 'Low']);
        Task::factory()->forProject($project)->priority(TaskPriority::High)->create(['title' => 'High']);
        Task::factory()->forProject($project)->priority(TaskPriority::Urgent)->create(['title' => 'Urgent']);
        Task::factory()->forProject($project)->done()->priority(TaskPriority::Urgent)->create(['title' => 'Done urgent']);

        $response = $this->getJson('/api/dashboard')->assertOk();

        $titles = array_column($response->json('data.priority_tasks'), 'title');

        $this->assertSame(['Urgent', 'High', 'Low'], $titles);
    }

    public function test_due_within_seven_days_counts_only_upcoming_unfinished_tasks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        Task::factory()->forProject($project)->create(['title' => 'Soon', 'due_at' => now()->addDays(3)]);
        Task::factory()->forProject($project)->create(['title' => 'Later', 'due_at' => now()->addDays(30)]);
        Task::factory()->forProject($project)->create(['title' => 'Past', 'due_at' => now()->subDay()]);
        Task::factory()->forProject($project)->done()->create(['title' => 'Done soon', 'due_at' => now()->addDays(2)]);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.tasks.due_within_seven_days', 1);
    }
}
