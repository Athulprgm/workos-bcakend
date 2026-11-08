<?php

namespace App\Http\Resources;

use App\Models\WorkLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkLog
 */
class WorkLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'workspace_name' => $this->workspace?->name,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'task_id' => $this->task_id,
            'task_title' => $this->task?->title,
            'work_description' => $this->work_description,
            'date' => $this->date?->format('Y-m-d'),
            'time_spent_minutes' => $this->time_spent_minutes,
            'problems_encountered' => $this->problems_encountered,
            'next_steps' => $this->next_steps,
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
