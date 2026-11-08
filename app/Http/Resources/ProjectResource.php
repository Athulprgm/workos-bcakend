<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tasksCount = $this->tasks_count ?? $this->tasks()->count();
        $completedCount = $this->completed_tasks_count ?? $this->tasks()->whereIn('status', ['done', 'completed'])->count();
        $progress = $tasksCount > 0 ? (int) round(($completedCount / $tasksCount) * 100) : 0;

        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority ?? 'medium',
            'tech_stack' => $this->tech_stack ?? [],
            'repository_url' => $this->repository_url,
            'start_date' => $this->start_date,
            'due_at' => $this->due_at,
            'notes' => $this->notes,
            'milestones' => $this->milestones ?? [],
            'archived_at' => $this->archived_at,
            'tasks_count' => $tasksCount,
            'completed_tasks_count' => $completedCount,
            'pending_tasks_count' => max(0, $tasksCount - $completedCount),
            'progress' => $progress,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
