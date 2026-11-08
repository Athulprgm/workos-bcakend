<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Tasks inherit their authorization from the project they belong to, so a user
 * can only ever reach tasks inside their own projects.
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return $this->ownsProjectOf($user, $task);
    }

    public function create(User $user, Project $project): bool
    {
        return (int) $project->user_id === (int) $user->id;
    }

    public function update(User $user, Task $task): bool
    {
        return $this->ownsProjectOf($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->ownsProjectOf($user, $task);
    }

    private function ownsProjectOf(User $user, Task $task): bool
    {
        return (int) $task->project->user_id === (int) $user->id;
    }
}
