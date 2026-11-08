<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class TeamMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->relationLoaded('testerAccessInvitations')
            ? $this->testerAccessInvitations->first()
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'role_label' => $this->roleLabel(),
            'is_active' => $this->isActive(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'is_self' => (int) $request->user()?->id === (int) $this->id,
            'is_super_admin' => $this->isSuperAdmin(),
            'projects_count' => $this->whenCounted('projects'),
            'tasks_count' => $this->whenCounted('tasks'),
            'assigned_bugs_count' => $this->whenCounted('assignedBugs'),
            'tester_invitation' => $invitation
                ? TesterInvitationResource::make($invitation->load('projects'))
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
