<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'category',
        'key',
        'content',
        'importance',
        'last_accessed_at',
    ];

    protected $casts = [
        'importance' => 'integer',
        'last_accessed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('content', 'like', "%{$term}%")
              ->orWhere('key', 'like', "%{$term}%")
              ->orWhere('category', 'like', "%{$term}%");
        });
    }

    public function markAccessed(): void
    {
        $this->updateQuietly(['last_accessed_at' => now()]);
    }
}

