<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => null,
            'parent_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => TaskStatus::Backlog,
            'priority' => TaskPriority::Medium,
            'due_at' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => ['project_id' => $project->id]);
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function priority(TaskPriority $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    /**
     * A task that is already finished.
     */
    public function done(): static
    {
        return $this->status(TaskStatus::Done);
    }
}
