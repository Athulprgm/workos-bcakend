<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'company_type' => $this->company_type,
            'color' => $this->color ?? '#6366F1',
            'settings' => $this->settings ?? [],
            'archived_at' => $this->archived_at,
            'is_archived' => $this->archived_at !== null,
            'projects_count' => $this->projects()->count(),
            'tasks_count' => $this->tasks()->count(),
            'active_tasks_count' => $this->tasks()->whereNotIn('status', ['done', 'cancelled'])->count(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
