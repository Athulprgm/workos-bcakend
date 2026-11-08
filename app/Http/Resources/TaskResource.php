<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id ?? $this->project?->workspace_id,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'due_at' => $this->due_at,
            'completed_at' => $this->completed_at,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'actual_time_spent_minutes' => $this->actual_time_spent_minutes ?? 0,
            'completion_notes' => $this->completion_notes,
            'recurring' => $this->recurring ?? 'none',
            'activity_history' => $this->activity_history ?? [],
            'is_overdue' => $this->isOverdue(),
            'is_blocked' => $this->whenLoaded('dependencies', fn (): bool => $this->isBlocked()),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'blocked_by' => $this->whenLoaded('dependencies', fn () => $this->dependencies
                ->map(fn (Task $task): array => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status->value,
                ])
                ->values()),
            'subtasks_count' => $this->whenCounted('subtasks'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! $this->status->isClosed();
    }
}
