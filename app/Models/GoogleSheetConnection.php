<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleSheetConnection extends Model
{
    protected $fillable = [
        'user_id',
        'google_account_id',
        'workspace_id',
        'project_id',
        'spreadsheet_id',
        'spreadsheet_url',
        'sheet_name',
        'header_row',
        'sync_enabled',
        'bidirectional',
        'conflict_policy',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
        'last_imported_count',
        'last_exported_count',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'bidirectional' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_imported_count' => 'integer',
            'last_exported_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function googleAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(SheetSyncConflict::class);
    }

    public function openConflicts(): HasMany
    {
        return $this->hasMany(SheetSyncConflict::class)->where('status', 'open');
    }
}
