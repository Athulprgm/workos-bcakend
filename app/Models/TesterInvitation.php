<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TesterInvitation extends Model
{
    protected $fillable = [
        'owner_id',
        'tester_user_id',
        'name',
        'email',
        'key_hash',
        'key_hint',
        'status',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tester_user_id');
    }

    public function projectAccess(): HasMany
    {
        return $this->hasMany(TesterProjectAccess::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'tester_project_access')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(function (Builder $inner): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
