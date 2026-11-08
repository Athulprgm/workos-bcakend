<?php

namespace Tests\Feature\Api;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_endpoints_require_authentication(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->postJson('/api/projects', ['name' => 'Nope'])->assertUnauthorized();
    }

    public function test_user_can_create_a_project(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/projects', [
            'name' => 'WorkOS Platform',
            'description' => 'The developer operating system',
            'tech_stack' => ['Laravel', 'React'],
            'repository_url' => 'https://github.com/example/workos',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'WorkOS Platform')
            ->assertJsonPath('data.slug', 'workos-platform')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.tech_stack', ['Laravel', 'React'])
            ->assertJsonPath('data.tasks_count', 0);

        $this->assertDatabaseHas('projects', [
            'user_id' => $user->id,
            'name' => 'WorkOS Platform',
            'slug' => 'workos-platform',
        ]);
    }

    public function test_project_slug_is_made_unique_per_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/projects', ['name' => 'Same Name'])->json('data.slug');
        $second = $this->postJson('/api/projects', ['name' => 'Same Name'])->json('data.slug');

        $this->assertSame('same-name', $first);
        $this->assertSame('same-name-2', $second);
    }

    public function test_two_users_may_each_own_the_same_slug(): void
    {
        $first = User::factory()->create();
        Sanctum::actingAs($first);
        $this->postJson('/api/projects', ['name' => 'Shared'])->assertCreated();

        $second = User::factory()->create();
        Sanctum::actingAs($second);

        $this->postJson('/api/projects', ['name' => 'Shared'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'shared');
    }

    public function test_project_creation_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/projects', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/projects', ['name' => 'X', 'status' => 'nonsense'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->postJson('/api/projects', ['name' => 'X', 'repository_url' => 'not-a-url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('repository_url');

        $this->postJson('/api/projects', ['name' => 'X', 'tech_stack' => 'Laravel'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tech_stack');
    }

    public function test_index_only_returns_the_callers_projects(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Project::factory()->for($user)->create(['name' => 'Mine']);
        Project::factory()->create(['name' => 'Theirs']);

        $response = $this->getJson('/api/projects');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_index_filters_by_status_and_search_term(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Project::factory()->for($user)->create([
            'name' => 'Alpha Engine', 'description' => 'first', 'status' => ProjectStatus::Active,
        ]);
        Project::factory()->for($user)->create([
            'name' => 'Beta Tool', 'description' => 'second', 'status' => ProjectStatus::Archived,
        ]);

        $this->getJson('/api/projects?status=archived')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beta Tool');

        $this->getJson('/api/projects?q=Alpha')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha Engine');

        $this->getJson('/api/projects?q=second')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beta Tool');

        $this->getJson('/api/projects?status=bogus')->assertStatus(422);
        $this->getJson('/api/projects?per_page=500')->assertStatus(422);
    }

    public function test_index_reports_the_number_of_tasks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        Task::factory()->forProject($project)->count(3)->create();

        $this->getJson('/api/projects')
            ->assertOk()
            ->assertJsonPath('data.0.tasks_count', 3);
    }

    public function test_index_is_paginated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Project::factory()->for($user)->count(3)->create();

        $this->getJson('/api/projects?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_user_cannot_touch_another_users_project(): void
    {
        $task = null;
        $project = Project::factory()->create(['name' => 'Untouchable']);
        $task = Task::factory()->forProject($project)->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/projects/{$project->id}")->assertForbidden();
        $this->patchJson("/api/projects/{$project->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->deleteJson("/api/projects/{$project->id}")->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Untouchable']);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_show_returns_the_project(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create(['name' => 'Only Mine']);

        $this->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Only Mine')
            ->assertJsonPath('data.tasks_count', 0);
    }

    public function test_update_changes_fields_and_regenerates_the_slug(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create(['name' => 'Old Name', 'slug' => 'old-name']);

        $this->patchJson("/api/projects/{$project->id}", ['name' => 'New Name', 'status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.slug', 'new-name')
            ->assertJsonPath('data.status', 'paused');
    }

    public function test_update_without_a_name_leaves_the_slug_alone(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create(['name' => 'Keep', 'slug' => 'keep']);

        $this->patchJson("/api/projects/{$project->id}", ['description' => 'changed'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'keep')
            ->assertJsonPath('data.description', 'changed');
    }

    public function test_deleting_a_project_also_removes_its_tasks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();
        $task = Task::factory()->forProject($project)->create();

        $this->deleteJson("/api/projects/{$project->id}")->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_archived_project_can_still_be_updated(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->archived()->create();

        $this->patchJson("/api/projects/{$project->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }
}
