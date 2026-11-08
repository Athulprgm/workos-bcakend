<?php

namespace App\Policies;

use App\Models\Bug;
use App\Models\User;

class BugPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Bug $bug): bool
    {
        return $this->canAccessProject($user, (int) $bug->project_id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Bug $bug): bool
    {
        return $this->canAccessProject($user, (int) $bug->project_id);
    }

    /**
     * Owners may delete any bug in their projects. Testers may delete bugs
     * they reported, but not records created by the owner/team.
     */
    public function delete(User $user, Bug $bug): bool
    {
        if (! $this->canAccessProject($user, (int) $bug->project_id)) {
            return false;
        }

        if (! $user->isTester()) {
            return true;
        }

        return (int) $bug->created_by === (int) $user->id;
    }

    private function canAccessProject(User $user, int $projectId): bool
    {
        if ($user->isTester()) {
            return in_array($projectId, $user->accessibleProjectIds() ?? [], true);
        }

        return $this->bugProjectExistsForOwner($user, $projectId);
    }

    private function bugProjectExistsForOwner(User $user, int $projectId): bool
    {
        return $user->projects()->whereKey($projectId)->exists();
    }
}
