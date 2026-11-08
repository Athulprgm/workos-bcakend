<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GithubLink extends Model
{
    protected $fillable = [
        'github_repository_link_id',
        'user_id',
        'github_type',
        'github_number',
        'title',
        'state',
        'html_url',
        'local_type',
        'local_id',
        'last_event',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'github_number' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function repositoryLink(): BelongsTo
    {
        return $this->belongsTo(GithubRepositoryLink::class, 'github_repository_link_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The linked Work OS record. Task and Bug are the only supported targets.
     */
    public function local(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'local_type', 'local_id');
    }
}
