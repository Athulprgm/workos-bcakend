<?php

namespace App\Http\Resources;

use App\Models\TesterInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TesterInvitation
 */
class TesterInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'key_hint' => $this->key_hint,
            'tester_user_id' => $this->tester_user_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'projects' => $this->whenLoaded('projects', fn () => $this->projects->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
            ])),
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('id')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
