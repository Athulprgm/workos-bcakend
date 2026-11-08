<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'github_user_id',
        'github_username',
        'name',
        'email',
        'avatar_url',
        'html_url',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }
}
