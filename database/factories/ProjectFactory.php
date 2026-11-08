<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->catchPhrase();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->paragraph(),
            'status' => ProjectStatus::Active,
            'tech_stack' => ['Laravel', 'React'],
            'repository_url' => 'https://github.com/example/'.Str::slug($name),
            'due_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => ProjectStatus::Archived]);
    }
}
