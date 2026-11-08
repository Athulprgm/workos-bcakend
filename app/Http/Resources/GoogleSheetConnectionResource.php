<?php

namespace App\Http\Resources;

use App\Models\GoogleSheetConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GoogleSheetConnection
 */
class GoogleSheetConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'spreadsheet_id' => $this->spreadsheet_id,
            'spreadsheet_url' => $this->spreadsheet_url,
            'sheet_name' => $this->sheet_name,
            'header_row' => $this->header_row,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'workspace_id' => $this->workspace_id,
            'sync_enabled' => (bool) $this->sync_enabled,
            'bidirectional' => (bool) $this->bidirectional,
            'conflict_policy' => $this->conflict_policy,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_sync_status' => $this->last_sync_status,
            'last_sync_error' => $this->last_sync_error,
            'last_imported_count' => $this->last_imported_count,
            'last_exported_count' => $this->last_exported_count,
            'open_conflicts_count' => $this->whenCounted('openConflicts'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
