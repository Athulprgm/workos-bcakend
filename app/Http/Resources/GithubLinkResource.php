<?php

namespace App\Http\Resources;

use App\Models\GithubLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GithubLink
 */
class GithubLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repository_link_id' => $this->github_repository_link_id,
            'github_type' => $this->github_type,
            'github_number' => $this->github_number,
            'title' => $this->title,
            'state' => $this->state,
            'html_url' => $this->html_url,
            'local_type' => $this->local_type,
            'local_id' => $this->local_id,
            'last_event' => $this->last_event,
            'synced_at' => $this->synced_at?->toIso8601String(),
        ];
    }
}
