<?php

namespace App\Http\Resources;

use App\Models\WorkReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkReport
 */
class WorkReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'workspace_id' => $this->workspace_id,
            'type' => $this->type,
            'period_start' => $this->period_start?->format('Y-m-d'),
            'period_end' => $this->period_end?->format('Y-m-d'),
            'date' => $this->period_start?->format('Y-m-d') ?? $this->created_at?->format('Y-m-d'),
            'project_name' => $this->project_name,
            'work_description' => $this->work_description,
            'status' => $this->status ?? 'Completed',
            'title' => $this->title,
            'company_name' => $this->company_name,
            'share_token' => $this->share_token,
            'public_url' => url("/reports/public/{$this->share_token}"),
            'visibility' => $this->visibility?->value ?? 'private',
            'shared_with_gm' => (bool) $this->shared_with_gm,
            'gm' => $this->whenLoaded('generalManager', fn () => $this->generalManager ? [
                'id' => $this->generalManager->id,
                'name' => $this->generalManager->name,
                'email' => $this->generalManager->email,
            ] : null),
            'shared_at' => $this->shared_at?->toIso8601String(),
            'owner' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'projects_worked' => $this->projects_worked ?? [],
            'table_data' => $this->tableData(),
            'completed_work' => $this->completed_work ?? [],
            'work_in_progress' => $this->work_in_progress ?? [],
            'pending_tasks' => $this->pending_tasks ?? [],
            'issues_blockers' => $this->issues_blockers ?? [],
            'time_spent_minutes' => $this->time_spent_minutes ?? 0,
            'next_steps' => $this->next_steps ?? [],
            'raw_content' => $this->raw_content,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
