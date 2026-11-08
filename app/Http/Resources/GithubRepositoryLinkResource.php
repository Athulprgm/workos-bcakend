<?php

namespace App\Http\Resources;

use App\Models\GithubRepositoryLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GithubRepositoryLink
 */
class GithubRepositoryLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repository_id' => $this->repository_id,
            'owner' => $this->owner,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'default_branch' => $this->default_branch,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'sync_issues' => (bool) $this->sync_issues,
            'sync_pull_requests' => (bool) $this->sync_pull_requests,
            'auto_sync' => (bool) $this->auto_sync,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_sync_status' => $this->last_sync_status,
            'last_sync_error' => $this->last_sync_error,
            'links_count' => $this->whenCounted('links'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
