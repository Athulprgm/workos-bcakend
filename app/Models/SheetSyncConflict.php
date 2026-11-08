<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetSyncConflict extends Model
{
    protected $fillable = [
        'google_sheet_connection_id',
        'bug_id',
        'row_key',
        'local_values',
        'remote_values',
        'fields',
        'status',
        'resolution',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'local_values' => 'array',
            'remote_values' => 'array',
            'fields' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleSheetConnection::class, 'google_sheet_connection_id');
    }

    public function bug(): BelongsTo
    {
        return $this->belongsTo(Bug::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
