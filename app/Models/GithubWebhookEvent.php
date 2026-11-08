<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GithubWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'event',
        'action',
        'repository',
        'sender',
        'payload',
        'processed',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed' => 'boolean',
        ];
    }
}
