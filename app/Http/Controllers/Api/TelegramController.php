<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramNotification;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TelegramController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'configured' => $this->telegram->isConfigured(),
                'enabled' => $this->telegram->isEnabled(),
                'bot' => $this->telegram->bot(),
                'token_hint' => $this->telegram->maskedToken(),
                'has_global_chat' => $this->telegram->hasGlobalChat(),
                'global_chat_id' => config('services.telegram.chat_id'),
                'chat_id' => $user->telegram_chat_id,
                'notifications_enabled' => $user->telegram_notifications_enabled,
                'events' => $user->telegram_events,
                'available_events' => TelegramService::eventOptions(),
                'recent' => $user->telegramNotifications()
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(fn (TelegramNotification $notification): array => $this->serializeNotification($notification))
                    ->values()
                    ->all(),
                'scheduled_digest' => '08:30',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($request->exists('chat_id') && is_string($request->input('chat_id'))) {
            $chatId = trim((string) $request->input('chat_id'));
            $request->merge(['chat_id' => $chatId === '' ? null : $chatId]);
        }

        $data = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:64', 'regex:/^(?:@[A-Za-z0-9_]{4,64}|-?\d{5,20})$/'],
            'enabled' => ['sometimes', 'boolean'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', Rule::in(array_keys(TelegramService::EVENTS))],
        ]);

        $user = $request->user();
        $chatId = array_key_exists('chat_id', $data) ? $data['chat_id'] : $user->telegram_chat_id;
        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : (bool) $user->telegram_notifications_enabled;

        if ($enabled && blank($chatId) && ! $this->telegram->hasGlobalChat()) {
            throw ValidationException::withMessages([
                'chat_id' => 'Add a Telegram chat ID before enabling notifications.',
            ]);
        }

        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => $enabled,
        ]);

        if (array_key_exists('events', $data)) {
            $user->telegram_events = array_values(array_unique($data['events']));
        }

        $user->save();

        return response()->json([
            'message' => 'Telegram notification settings saved.',
            'data' => $this->settingsPayload($user),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        if ($request->exists('chat_id') && is_string($request->input('chat_id'))) {
            $chatId = trim((string) $request->input('chat_id'));
            $request->merge(['chat_id' => $chatId === '' ? null : $chatId]);
        }

        $data = $request->validate([
            'chat_id' => ['nullable', 'string', 'max:64', 'regex:/^(?:@[A-Za-z0-9_]{4,64}|-?\d{5,20})$/'],
        ]);

        $user = $request->user();
        $chatId = $data['chat_id'] ?? $user->telegram_chat_id ?? config('services.telegram.chat_id');

        if (blank($chatId)) {
            throw ValidationException::withMessages([
                'chat_id' => 'Add a Telegram chat ID before sending a test message.',
            ]);
        }

        if (! $this->telegram->isConfigured()) {
            return response()->json([
                'message' => 'TELEGRAM_BOT_TOKEN is not configured on the server.',
            ], 422);
        }

        $text = $this->telegram->message('✅ WorkOS Telegram is connected', [
            'Account' => $user->name,
            'Time' => now()->format('M d, Y H:i'),
            'Bot' => ($this->telegram->bot() ?? [])['username'] ?? null,
        ]);

        $result = $this->telegram->sendMessage((string) $chatId, $text);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['error'] ?? 'Telegram could not deliver the test message.',
            ], 502);
        }

        return response()->json([
            'message' => 'Test message sent. Check Telegram.',
            'data' => [
                'chat_id' => (string) $chatId,
                'message_id' => $result['message_id'] ?? null,
            ],
        ]);
    }

    public function discoverChat(Request $request): JsonResponse
    {
        try {
            $chats = $this->telegram->discoverChats();
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => $chats === []
                ? 'No chats found. Open the bot in Telegram, send /start, then try again.'
                : 'Chats found. Choose one to receive notifications.',
            'data' => $chats,
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->telegramNotifications()
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $notifications->getCollection()
                ->map(fn (TelegramNotification $notification): array => $this->serializeNotification($notification))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function retry(Request $request, TelegramNotification $notification): JsonResponse
    {
        if ((int) $notification->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $result = $this->telegram->sendMessage($notification->chat_id, $notification->message);

        $notification->update([
            'status' => $result['ok'] ? 'sent' : 'failed',
            'error' => $result['ok'] ? null : ($result['error'] ?? 'Unknown Telegram error.'),
            'sent_at' => $result['ok'] ? now() : null,
        ]);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['error'] ?? 'Telegram could not deliver the message.',
            ], 502);
        }

        return response()->json([
            'message' => 'Notification sent again.',
            'data' => $this->serializeNotification($notification->refresh()),
        ]);
    }

    /**
     * Handle incoming webhook updates from Telegram Bot API.
     */
    public function webhook(Request $request, \App\Services\TelegramCommandHandler $handler): JsonResponse
    {
        $secret = config('services.telegram.webhook_secret');
        if (filled($secret)) {
            $headerSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($headerSecret !== $secret) {
                return response()->json(['error' => 'Invalid webhook secret token.'], 403);
            }
        }

        $update = $request->all();
        if (empty($update) || ! is_array($update)) {
            return response()->json(['error' => 'Empty update payload.'], 400);
        }

        try {
            $handler->handle($update);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Telegram webhook processing error', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{chat_id: string|null, notifications_enabled: bool, events: array<int, string>|null}
     */
    private function settingsPayload(User $user): array
    {
        return [
            'chat_id' => $user->telegram_chat_id,
            'notifications_enabled' => (bool) $user->telegram_notifications_enabled,
            'events' => $user->telegram_events,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNotification(TelegramNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'event' => $notification->event,
            'event_label' => TelegramService::eventLabel($notification->event),
            'status' => $notification->status,
            'chat_id' => $notification->chat_id,
            'message' => $notification->message,
            'error' => $notification->error,
            'sent_at' => $notification->sent_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
