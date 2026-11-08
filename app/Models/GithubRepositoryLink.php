<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubRepositoryLink extends Model
{
    protected $fillable = [
        'user_id',
        'github_account_id',
        'project_id',
        'repository_id',
        'owner',
        'name',
        'full_name',
        'default_branch',
        'sync_issues',
        'sync_pull_requests',
        'auto_sync',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'sync_issues' => 'boolean',
            'sync_pull_requests' => 'boolean',
            'auto_sync' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GithubAccount::class, 'github_account_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(GithubLink::class);
    }
}
