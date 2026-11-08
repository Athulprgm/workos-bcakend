<?php

namespace App\Models;

use Database\Factories\TelegramCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'update_id',
    'user_id',
    'telegram_user_id',
    'chat_id',
    'command',
    'arguments',
    'payload',
    'status',
    'response',
    'error',
    'processed_at',
])]
class TelegramCommand extends Model
{
    /** @use HasFactory<TelegramCommandFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_IGNORED = 'ignored';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
