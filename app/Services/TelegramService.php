<?php

namespace App\Services;

use App\Models\TelegramNotification;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Sends WorkOS activity notifications through the Telegram Bot API.
 *
 * The bot token stays server-side. Recipients can be configured globally with
 * TELEGRAM_CHAT_ID or per user from the Settings screen. Every attempt is
 * recorded in telegram_notifications so failures are visible and retryable.
 */
class TelegramService
{
    public const EVENT_TASK_CREATED = 'task_created';

    public const EVENT_TASK_UPDATED = 'task_updated';

    public const EVENT_TASK_COMPLETED = 'task_completed';

    public const EVENT_BUG_CREATED = 'bug_created';

    public const EVENT_BUG_UPDATED = 'bug_updated';

    public const EVENT_WORK_LOG_CREATED = 'work_log_created';

    public const EVENT_WORK_REPORT_CREATED = 'work_report_created';

    public const EVENT_WORK_REPORT_SHARED = 'work_report_shared';

    public const EVENT_GITHUB_EVENT = 'github_event';

    public const EVENT_DAILY_DIGEST = 'daily_digest';

    /**
     * Selectable notification events shown in the UI. `null` means all events.
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        self::EVENT_TASK_CREATED => 'Task created',
        self::EVENT_TASK_UPDATED => 'Task updated',
        self::EVENT_TASK_COMPLETED => 'Task completed',
        self::EVENT_BUG_CREATED => 'Bug reported',
        self::EVENT_BUG_UPDATED => 'Bug updated',
        self::EVENT_WORK_LOG_CREATED => 'Work log added',
        self::EVENT_WORK_REPORT_CREATED => 'Work report generated',
        self::EVENT_WORK_REPORT_SHARED => 'Report shared with me',
        self::EVENT_GITHUB_EVENT => 'GitHub webhook activity',
        self::EVENT_DAILY_DIGEST => 'Daily digest',
    ];

    private const BOT_CACHE_KEY = 'workos.telegram.bot';

    public function isConfigured(): bool
    {
        return $this->botToken() !== null;
    }

    public function isEnabled(): bool
    {
        return $this->isConfigured() && (bool) config('services.telegram.enabled', true);
    }

    public function hasGlobalChat(): bool
    {
        return filled(config('services.telegram.chat_id'));
    }

    /**
     * Metadata for the bot without exposing its token.
     *
     * @return array{id: int|string|null, name: string|null, username: string|null}|null
     */
    public function bot(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $cached = Cache::get(self::BOT_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client(3, 5)->get('getMe');

            if (! $response->successful() || ! $response->json('ok')) {
                return null;
            }

            $result = $response->json('result');

            if (! is_array($result)) {
                return null;
            }

            $bot = [
                'id' => $result['id'] ?? null,
                'name' => $result['first_name'] ?? null,
                'username' => $result['username'] ?? null,
            ];

            Cache::put(self::BOT_CACHE_KEY, $bot, now()->addMinutes(10));

            return $bot;
        } catch (Throwable $exception) {
            Log::warning('Telegram getMe request failed.', ['error' => $this->redact($exception)]);

            return null;
        }
    }

    public function maskedToken(): ?string
    {
        $token = $this->botToken();

        if ($token === null) {
            return null;
        }

        if (strlen($token) <= 12) {
            return str_repeat('•', 8);
        }

        return substr($token, 0, 8).'…'.substr($token, -4);
    }

    /**
     * Compose an HTML message that is safe for Telegram's HTML parse mode.
     *
     * @param  array<string, string|int|float|null>  $fields
     */
    public function message(string $title, array $fields = []): string
    {
        $lines = ['<b>'.$this->escape($title).'</b>'];

        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = '<b>'.$this->escape($label).':</b> '.$this->escape((string) $value);
        }

        return implode("\n", $lines);
    }

    /**
     * Send a message to every recipient, avoiding duplicate chat IDs.
     *
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $context
     * @return array<int, TelegramNotification>
     */
    public function sendToUsers(iterable $users, string $event, string $text, array $context = []): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $deliveredChats = [];
        $notifications = [];

        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }

            if (filled($user->telegram_chat_id)) {
                if (! $user->telegram_notifications_enabled || ! $this->wantsEvent($user, $event)) {
                    continue;
                }

                $chatId = (string) $user->telegram_chat_id;
            } else {
                $chatId = (string) config('services.telegram.chat_id');
            }

            if ($chatId === '' || isset($deliveredChats[$chatId])) {
                continue;
            }

            $deliveredChats[$chatId] = true;
            $notification = $this->recordAndSend($user, $event, $chatId, $text, $context);

            if ($notification instanceof TelegramNotification) {
                $notifications[] = $notification;
            }
        }

        return $notifications;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function sendToUser(?User $user, string $event, string $text, array $context = []): ?TelegramNotification
    {
        if ($user === null) {
            return $this->sendToGlobal($event, $text, $context);
        }

        return $this->sendToUsers([$user], $event, $text, $context)[0] ?? null;
    }

    /**
     * Send to the TELEGRAM_CHAT_ID fallback without a user-specific filter.
     *
     * @param  array<string, mixed>  $context
     */
    public function sendToGlobal(string $event, string $text, array $context = []): ?TelegramNotification
    {
        $chatId = (string) config('services.telegram.chat_id');

        if ($chatId === '') {
            return null;
        }

        return $this->recordAndSend(null, $event, $chatId, $text, $context);
    }

    /**
     * Send a plain message. Used by the connection test and retry endpoint.
     *
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, message_id?: int, error?: string}
     */
    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'error' => 'Telegram notifications are not configured.'];
        }

        try {
            $response = $this->client()->post('sendMessage', array_merge([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ], $options));

            if ($response->successful() && $response->json('ok') === true) {
                return [
                    'ok' => true,
                    'message_id' => (int) $response->json('result.message_id'),
                ];
            }

            $error = (string) ($response->json('description') ?? 'Telegram rejected the message.');

            Log::warning('Telegram message was rejected.', [
                'status' => $response->status(),
                'error' => $error,
            ]);

            return ['ok' => false, 'error' => $error];
        } catch (Throwable $exception) {
            Log::warning('Telegram message could not be delivered.', ['error' => $this->redact($exception)]);

            return ['ok' => false, 'error' => 'Could not reach Telegram. Check the bot token and network connection.'];
        }
    }

    /**
     * Acknowledge a callback query from an inline keyboard button.
     *
     * @return array{ok: bool, error?: string}
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'error' => 'Telegram is not enabled.'];
        }

        try {
            $payload = ['callback_query_id' => $callbackQueryId];
            if ($text !== null && $text !== '') {
                $payload['text'] = $text;
            }
            if ($showAlert) {
                $payload['show_alert'] = true;
            }

            $response = $this->client()->post('answerCallbackQuery', $payload);

            return [
                'ok' => $response->successful() && $response->json('ok') === true,
                'error' => $response->json('description'),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $this->redact($exception)];
        }
    }

    /**
     * Chat IDs seen by the bot. The owner must message the bot first.
     *
     * @return array<int, array{id: string, title: string, type: string, username: string|null}>
     */
    public function discoverChats(): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Set TELEGRAM_BOT_TOKEN before discovering chats.');
        }

        try {
            $response = $this->client()->get('getUpdates', ['limit' => 100, 'timeout' => 0]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not reach Telegram: '.$this->redact($exception), previous: $exception);
        }

        if (! $response->successful() || ! $response->json('ok')) {
            throw new RuntimeException((string) ($response->json('description') ?? 'Telegram rejected the request.'));
        }

        $chats = [];

        foreach ($response->json('result', []) as $update) {
            if (! is_array($update)) {
                continue;
            }

            $message = $update['message']
                ?? $update['edited_message']
                ?? $update['channel_post']
                ?? $update['edited_channel_post']
                ?? null;

            if (! is_array($message) || ! isset($message['chat']) || ! is_array($message['chat'])) {
                continue;
            }

            $chat = $message['chat'];
            $id = (string) ($chat['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $chats[$id] = [
                'id' => $id,
                'title' => (string) ($chat['title'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: $id),
                'type' => (string) ($chat['type'] ?? 'private'),
                'username' => isset($chat['username']) ? (string) $chat['username'] : null,
            ];
        }

        return array_values($chats);
    }

    /**
     * Long-poll for new updates. The HTTP timeout stays above Telegram's own
     * long-poll timeout so a quiet poll never becomes a connection failure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset = 0, int $timeout = 30, int $limit = 100): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Set TELEGRAM_BOT_TOKEN before polling for updates.');
        }

        try {
            $response = $this->client(10, $timeout + 10)
                ->asForm()
                ->post('getUpdates', [
                    'offset' => $offset,
                    'limit' => $limit,
                    'timeout' => $timeout,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Could not reach Telegram: '.$this->redact($exception), previous: $exception);
        }

        if (! $response->successful() || ! $response->json('ok')) {
            $description = (string) ($response->json('description') ?? 'Telegram rejected the getUpdates request.');

            if (Str::contains(Str::lower($description), 'webhook')) {
                $description .= ' Delete the active Telegram webhook before starting the poller.';
            }

            throw new RuntimeException($this->redact($description));
        }

        $result = $response->json('result', []);

        if (! is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, 'is_array'));
    }

    public function wantsEvent(User $user, string $event): bool
    {
        if ($user->telegram_events === null) {
            return true;
        }

        return in_array($event, $user->telegram_events, true);
    }

    /**
     * @return array<string, string>
     */
    public static function eventOptions(): array
    {
        return self::EVENTS;
    }

    public static function eventLabel(string $event): string
    {
        return self::EVENTS[$event] ?? str($event)->replace('_', ' ')->title()->toString();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordAndSend(?User $user, string $event, string $chatId, string $text, array $context): ?TelegramNotification
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $notification = null;

        try {
            $notification = TelegramNotification::create([
                'user_id' => $user?->id,
                'workspace_id' => $context['workspace_id'] ?? null,
                'event' => $event,
                'chat_id' => $chatId,
                'message' => $text,
                'status' => 'pending',
                'payload' => $context['payload'] ?? null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Telegram notification could not be recorded.', ['error' => $exception->getMessage()]);
        }

        $result = $this->sendMessage($chatId, $text);

        if ($notification instanceof TelegramNotification) {
            try {
                $notification->update([
                    'status' => $result['ok'] ? 'sent' : 'failed',
                    'error' => $result['ok'] ? null : ($result['error'] ?? 'Unknown Telegram error.'),
                    'sent_at' => $result['ok'] ? now() : null,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Telegram notification result could not be saved.', ['error' => $exception->getMessage()]);
            }
        }

        return $notification;
    }

    private function client(int $connectTimeout = 3, int $timeout = 8): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        $apiUrl = rtrim((string) config('services.telegram.api_url', 'https://api.telegram.org'), '/');

        return $apiUrl.'/bot'.$this->botToken();
    }

    private function botToken(): ?string
    {
        $token = config('services.telegram.bot_token');

        return filled($token) ? (string) $token : null;
    }

    /**
     * Remove the bot token from HTTP client exception messages before logging
     * or returning them, because Guzzle includes the full request URL.
     */
    private function redact(Throwable|string $value): string
    {
        $message = $value instanceof Throwable ? $value->getMessage() : $value;
        $token = $this->botToken();

        if ($token === null) {
            return $message;
        }

        return str_replace($token, '[redacted]', $message);
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
