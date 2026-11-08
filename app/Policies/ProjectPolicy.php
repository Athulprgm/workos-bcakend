<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Projects are private to their owner. Every project-scoped decision in the
 * application resolves through this policy, never through the frontend.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->owns($user, $project);
    }

    private function owns(User $user, Project $project): bool
    {
        return (int) $project->user_id === (int) $user->id;
    }
}
