<?php

namespace App\Http\Resources;

use App\Models\Bug;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bug
 */
class BugResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bug_id' => $this->reference(),
            'number' => $this->number,
            'workspace_id' => $this->workspace_id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'title' => $this->title,
            'details' => $this->details,
            'priority' => $this->priority?->value,
            'status' => $this->status?->value,
            'tester' => $this->whenLoaded('tester', fn () => $this->tester ? [
                'id' => $this->tester->id,
                'name' => $this->tester->name,
                'email' => $this->tester->email,
            ] : null),
            'tester_id' => $this->tester_id,
            'tester_name' => $this->displayTesterName(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'testing_notes' => $this->testing_notes,
            'validated' => (bool) $this->validated,
            'validated_by' => $this->whenLoaded('validator', fn () => $this->validator ? [
                'id' => $this->validator->id,
                'name' => $this->validator->name,
            ] : null),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'source' => $this->source?->value,
            'external_key' => $this->external_key,
            'external_updated_at' => $this->external_updated_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'github_links' => $this->whenLoaded('githubLinks', fn () => GithubLinkResource::collection($this->githubLinks)),
        ];
    }
}
