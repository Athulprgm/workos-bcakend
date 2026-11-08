<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TelegramCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Processes authorized Telegram messages into WorkOS actions.
 *
 * Only private chats from configured Telegram user IDs are accepted. Every
 * update is recorded by its Telegram update_id so a redelivered update cannot
 * run the same action twice.
 */
class TelegramCommandHandler
{
    private const MAX_COMMANDS_PER_MINUTE = 20;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly AiTelegramReportService $aiReportService,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): ?TelegramCommand
    {
        $updateId = (int) ($update['update_id'] ?? 0);

        if ($updateId <= 0) {
            return null;
        }

        // Handle inline button callback queries (e.g. project selection or edit prompt)
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->handleCallbackQuery($update, $updateId);
        }

        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return null;
        }

        $from = $message['from'] ?? null;
        $chat = $message['chat'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (! is_array($from) || ! is_array($chat) || $text === '') {
            return null;
        }

        $fromId = (string) ($from['id'] ?? '');
        $chatId = (string) ($chat['id'] ?? '');
        $chatType = (string) ($chat['type'] ?? '');

        if ($fromId === '' || $chatId === '' || ($from['is_bot'] ?? false) === true) {
            return null;
        }

        if (! $this->isAuthorized($fromId)) {
            Log::warning('Unauthorized Telegram command ignored.', [
                'telegram_user_id' => $fromId,
                'chat_id' => $chatId,
            ]);

            if (RateLimiter::attempt('telegram-denied:'.$chatId, 3, fn (): bool => true, 60)) {
                $this->telegram->sendMessage($chatId, '⛔ You are not authorized to use this WorkOS bot.');
            }

            return null;
        }

        $existing = TelegramCommand::query()->where('update_id', $updateId)->first();

        if ($existing instanceof TelegramCommand) {
            return $existing;
        }

        if ($chatType !== 'private') {
            $this->telegram->sendMessage($chatId, '⚠️ WorkOS commands are only accepted in a private chat with the bot.');

            return $this->recordIgnored($update, 'Commands are only accepted in private chats.');
        }

        $user = User::query()->where('telegram_chat_id', $chatId)->first();

        if (! $user instanceof User) {
            $this->telegram->sendMessage($chatId, '⚠️ This Telegram chat is not linked to a WorkOS account. Save this chat ID in WorkOS Settings → Telegram.');

            return $this->recordIgnored($update, 'Telegram chat is not linked to a WorkOS user.');
        }

        $rateLimitKey = 'telegram-command:'.$user->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_COMMANDS_PER_MINUTE)) {
            $this->telegram->sendMessage($chatId, '⚠️ Too many commands. Try again in a minute.');

            return $this->recordIgnored($update, 'Rate limited.', $user);
        }

        RateLimiter::hit($rateLimitKey, 60);

        [$command, $arguments] = $this->parseCommand($text);

        /**
         * @var array{0: TelegramCommand, 1: string|null, 2?: array<string, mixed>} $result
         */
        $result = DB::transaction(function () use ($update, $updateId, $user, $fromId, $chatId, $command, $arguments, $text): array {
            $commandRow = TelegramCommand::query()->firstOrCreate(
                ['update_id' => $updateId],
                [
                    'user_id' => $user->id,
                    'telegram_user_id' => $fromId,
                    'chat_id' => $chatId,
                    'command' => $command ?? 'ai_report',
                    'arguments' => $arguments === [] ? null : implode(' ', $arguments),
                    'payload' => $update,
                    'status' => TelegramCommand::STATUS_RECEIVED,
                ],
            );

            if (! $commandRow->wasRecentlyCreated) {
                return [$commandRow, null, []];
            }

            $options = [];

            try {
                if ($command === null) {
                    $reportResult = $this->aiReportService->handleMessage($text, $user, $chatId);
                    $response = $reportResult['text'];
                    $options = $reportResult['options'] ?? [];

                    $commandRow->update([
                        'command' => 'ai_daily_report',
                        'status' => TelegramCommand::STATUS_PROCESSED,
                        'response' => $response,
                        'processed_at' => now(),
                    ]);
                } else {
                    $response = $this->dispatch($command, $arguments, $user);
                    $commandRow->update([
                        'status' => TelegramCommand::STATUS_PROCESSED,
                        'response' => $response,
                        'processed_at' => now(),
                    ]);
                }
            } catch (InvalidArgumentException $exception) {
                $response = '⚠️ '.$exception->getMessage();
                $commandRow->update([
                    'status' => TelegramCommand::STATUS_FAILED,
                    'response' => $response,
                    'error' => $exception->getMessage(),
                    'processed_at' => now(),
                ]);
            } catch (Throwable $exception) {
                Log::error('Telegram command failed.', [
                    'update_id' => $updateId,
                    'command' => $command,
                    'error' => $exception->getMessage(),
                ]);

                $response = '⚠️ Something went wrong while processing that command. Please try again.';
                $commandRow->update([
                    'status' => TelegramCommand::STATUS_FAILED,
                    'response' => $response,
                    'error' => $exception->getMessage(),
                    'processed_at' => now(),
                ]);
            }

            return [$commandRow, $response, $options];
        });

        [$commandRow, $response, $options] = $result;

        if ($response !== null) {
            $delivery = $this->telegram->sendMessage($chatId, $response, $options ?? []);

            if (! $delivery['ok']) {
                $commandRow->update([
                    'error' => $delivery['error'] ?? 'Telegram could not deliver the reply.',
                ]);
            }
        }

        return $commandRow;
    }

    /**
     * Handle incoming inline keyboard button interactions (callback_query).
     *
     * @param  array<string, mixed>  $update
     */
    private function handleCallbackQuery(array $update, int $updateId): ?TelegramCommand
    {
        $cq = $update['callback_query'] ?? [];
        $queryId = (string) ($cq['id'] ?? '');
        $from = $cq['from'] ?? null;
        $message = $cq['message'] ?? null;
        $data = (string) ($cq['data'] ?? '');

        if (! is_array($from) || ! is_array($message) || $data === '') {
            return null;
        }

        $fromId = (string) ($from['id'] ?? '');
        $chat = $message['chat'] ?? null;
        $chatId = (string) ($chat['id'] ?? '');

        if (! $this->isAuthorized($fromId)) {
            $this->telegram->answerCallbackQuery($queryId, 'Unauthorized', true);

            return null;
        }

        $user = User::query()->where('telegram_chat_id', $chatId)->first();
        if (! $user instanceof User) {
            $this->telegram->answerCallbackQuery($queryId, 'Account not linked', true);

            return null;
        }

        if (str_starts_with($data, 'select_project:')) {
            $projectId = (int) substr($data, strlen('select_project:'));
            $this->telegram->answerCallbackQuery($queryId, 'Project selected!');
            $result = $this->aiReportService->handleProjectSelection($projectId, $user);
            $this->telegram->sendMessage($chatId, $result['text'], $result['options'] ?? []);

            return TelegramCommand::query()->firstOrCreate(
                ['update_id' => $updateId],
                [
                    'user_id' => $user->id,
                    'telegram_user_id' => $fromId,
                    'chat_id' => $chatId,
                    'command' => 'select_project',
                    'arguments' => (string) $projectId,
                    'payload' => $update,
                    'status' => TelegramCommand::STATUS_PROCESSED,
                    'response' => $result['text'],
                    'processed_at' => now(),
                ]
            );
        }

        if (str_starts_with($data, 'edit_report:')) {
            $reportId = (int) substr($data, strlen('edit_report:'));
            $this->telegram->answerCallbackQuery($queryId);
            $editHelp = "✏️ <b>Edit Daily Report #{$reportId}</b>\n\nSimply reply with what you want to change, for example:\n• <i>'Change status to Completed'</i>\n• <i>'Add mobile responsiveness testing'</i>\n• <i>'Change work date to yesterday'</i>";
            $this->telegram->sendMessage($chatId, $editHelp);

            return TelegramCommand::query()->firstOrCreate(
                ['update_id' => $updateId],
                [
                    'user_id' => $user->id,
                    'telegram_user_id' => $fromId,
                    'chat_id' => $chatId,
                    'command' => 'edit_report_prompt',
                    'arguments' => (string) $reportId,
                    'payload' => $update,
                    'status' => TelegramCommand::STATUS_PROCESSED,
                    'response' => $editHelp,
                    'processed_at' => now(),
                ]
            );
        }

        $this->telegram->answerCallbackQuery($queryId);

        return null;
    }

    private function isAuthorized(string $telegramUserId): bool
    {
        $allowedUserIds = config('services.telegram.allowed_user_ids', []);

        if (! is_array($allowedUserIds)) {
            return false;
        }

        foreach ($allowedUserIds as $allowedUserId) {
            if ((string) $allowedUserId === $telegramUserId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string|null, 1: array<int, string>}
     */
    private function parseCommand(string $text): array
    {
        if (! str_starts_with($text, '/')) {
            return [null, []];
        }

        $parts = preg_split('/\s+/u', trim($text)) ?: [];
        $rawCommand = (string) array_shift($parts);
        $command = Str::lower(ltrim($rawCommand, '/'));
        $command = explode('@', $command, 2)[0];
        $command = Str::limit($command, 64, '');

        if ($command === '') {
            return [null, []];
        }

        return [$command, array_values($parts)];
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function dispatch(string $command, array $arguments, User $user): string
    {
        return match ($command) {
            'start', 'help' => $this->help($user),
            'tasks', 'list' => $this->listTasks($user, $arguments),
            'task', 'show' => $this->taskDetails($user, $arguments[0] ?? null),
            'status' => $this->changeStatus($user, $arguments[0] ?? null, $arguments[1] ?? null),
            'done', 'complete' => $this->changeStatus($user, $arguments[0] ?? null, 'done'),
            'log' => $this->addWorkLog($user, $arguments[0] ?? null, $arguments[1] ?? null, array_slice($arguments, 2)),
            'note', 'update' => $this->addNote($user, $arguments[0] ?? null, array_slice($arguments, 1)),
            default => throw new InvalidArgumentException('Unknown command. Send /help to see the available commands.'),
        };
    }

    private function help(User $user): string
    {
        return implode("\n", [
            '<b>WorkOS Telegram commands</b>',
            'Linked account: <b>'.$this->e($user->name).'</b>',
            '',
            '/help — show this message',
            '/tasks [open|today|overdue|done] — list your tasks',
            '/task &lt;id&gt; — task details',
            '/status &lt;id&gt; &lt;status&gt; — change a task status',
            '/done &lt;id&gt; — mark a task done',
            '/log &lt;id&gt; &lt;minutes&gt; [note] — add a work log',
            '/note &lt;id&gt; &lt;text&gt; — add a work update',
            '',
            'Statuses: '.$this->statusList(),
            'Only tasks in projects you own are accessible.',
        ]);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private function listTasks(User $user, array $arguments): string
    {
        $filter = Str::lower($arguments[0] ?? 'open');

        $query = Task::query()
            ->whereHas('project', fn (Builder $query) => $query->where('user_id', $user->id))
            ->with('project:id,name');

        $heading = match ($filter) {
            '', 'open' => 'Open tasks',
            'today' => 'Tasks due today',
            'overdue' => 'Overdue tasks',
            'done', 'completed' => 'Completed tasks',
            default => throw new InvalidArgumentException('Usage: /tasks [open|today|overdue|done]'),
        };

        match ($filter) {
            'today' => $query->whereNotIn('status', $this->closedStatuses())
                ->whereDate('due_at', today())
                ->orderBy('due_at'),
            'overdue' => $query->overdue()->orderBy('due_at'),
            'done', 'completed' => $query->where('status', TaskStatus::Done->value)
                ->latest('updated_at'),
            default => $query->whereNotIn('status', $this->closedStatuses())
                ->orderByRaw('due_at IS NULL')
                ->orderBy('due_at'),
        };

        $tasks = $query->orderByDesc('id')->limit(10)->get();

        if ($tasks->isEmpty()) {
            return '<b>'.$heading.'</b>'."\n\n".'Nothing here. 🎉';
        }

        $lines = ['<b>'.$heading.'</b>', ''];

        foreach ($tasks as $task) {
            $status = $task->status ? Str::headline($task->status->value) : 'Unknown';
            $due = $task->due_at ? ' · due '.$task->due_at->format('M d') : '';
            $project = $task->project?->name ? ' · '.$this->e($task->project->name) : '';

            $lines[] = '<b>#'.$task->id.'</b> '.$this->e(Str::limit($task->title, 80)).' — '.$this->e($status).$due.$project;
        }

        $lines[] = '';
        $lines[] = 'Use /task &lt;id&gt; for details.';

        return implode("\n", $lines);
    }

    private function taskDetails(User $user, ?string $taskId): string
    {
        $task = $this->findTask($user, $taskId);

        return $this->telegram->message('#'.$task->id.' '.$task->title, [
            'Project' => $task->project?->name,
            'Status' => $task->status ? Str::headline($task->status->value) : null,
            'Priority' => $task->priority ? Str::headline($task->priority->value) : null,
            'Assigned to' => $task->assignee?->name,
            'Due' => $task->due_at?->format('M d, Y H:i'),
            'Completed' => $task->completed_at?->format('M d, Y H:i'),
            'Description' => $task->description ? Str::limit($task->description, 300) : null,
            'Change status' => '/status '.$task->id.' <status>',
            'Add a work log' => '/log '.$task->id.' <minutes> <note>',
        ]);
    }

    private function changeStatus(User $user, ?string $taskId, ?string $statusValue): string
    {
        $task = $this->findTask($user, $taskId);
        $status = $this->parseStatus($statusValue);
        $previousStatus = $task->status;

        if ($previousStatus === $status) {
            return 'ℹ️ <b>#'.$task->id.' '.$this->e($task->title).'</b> is already <b>'.$this->e(Str::headline($status->value)).'</b>.';
        }

        $history = $task->activity_history ?? [];
        $history[] = [
            'event' => 'status_change',
            'description' => 'Status changed from '.($previousStatus?->value ?? 'unknown').' to '.$status->value.' via Telegram',
            'timestamp' => now()->toIso8601String(),
            'user' => $user->name,
        ];

        $task->status = $status;
        $task->activity_history = $history;
        $task->save();

        return '✅ <b>#'.$task->id.' '.$this->e($task->title).'</b> is now <b>'.$this->e(Str::headline($status->value)).'</b>.';
    }

    private function parseStatus(?string $value): TaskStatus
    {
        if (blank($value)) {
            throw new InvalidArgumentException('Provide a status, for example /status 42 in_progress. Statuses: '.$this->statusList().'.');
        }

        $normalized = Str::lower(trim($value));
        $normalized = str_replace([' ', '-'], '_', $normalized);
        $normalized = match ($normalized) {
            'inprogress' => 'in_progress',
            'progress' => 'in_progress',
            'complete' => 'done',
            'completed' => 'done',
            'cancel' => 'cancelled',
            'canceled' => 'cancelled',
            'to_do' => 'todo',
            default => $normalized,
        };

        $status = TaskStatus::tryFrom($normalized);

        if (! $status instanceof TaskStatus) {
            throw new InvalidArgumentException('Unknown status “'.$this->e(Str::limit($value, 40, '')).'”. Use one of: '.$this->statusList().'.');
        }

        return $status;
    }

    /**
     * @param  array<int, string>  $noteParts
     */
    private function addWorkLog(User $user, ?string $taskId, ?string $minutes, array $noteParts): string
    {
        $task = $this->findTask($user, $taskId);
        $minutesValue = filter_var($minutes, FILTER_VALIDATE_INT);

        if ($minutesValue === false || $minutesValue < 0 || $minutesValue > 1440) {
            throw new InvalidArgumentException('Usage: /log &lt;id&gt; &lt;minutes&gt; [note]. Minutes must be a whole number from 0 to 1440.');
        }

        $description = trim(implode(' ', $noteParts));

        if ($description === '') {
            $description = 'Worked on '.$task->title;
        }

        $description = Str::limit($description, 20000, '');

        $workspaceId = $task->workspace_id
            ?? $task->project?->workspace_id
            ?? $user->workspaces()->active()->value('id');

        if (! $workspaceId || ! $user->workspaces()->whereKey($workspaceId)->exists()) {
            throw new InvalidArgumentException('This task is not attached to one of your workspaces. Add a workspace in WorkOS first.');
        }

        $user->workLogs()->create([
            'workspace_id' => $workspaceId,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'work_description' => $description,
            'date' => today()->format('Y-m-d'),
            'time_spent_minutes' => $minutesValue,
        ]);

        return '✅ Logged <b>'.$minutesValue.'m</b> on <b>#'.$task->id.' '.$this->e($task->title).'</b>.'."\n".$this->e(Str::limit($description, 200));
    }

    /**
     * @param  array<int, string>  $noteParts
     */
    private function addNote(User $user, ?string $taskId, array $noteParts): string
    {
        $task = $this->findTask($user, $taskId);
        $note = trim(implode(' ', $noteParts));

        if ($note === '') {
            throw new InvalidArgumentException('Usage: /note &lt;id&gt; &lt;text&gt;. Add the update text after the task ID.');
        }

        $note = Str::limit($note, 2000, '');
        $history = $task->activity_history ?? [];
        $history[] = [
            'event' => 'telegram_update',
            'description' => $note,
            'timestamp' => now()->toIso8601String(),
            'user' => $user->name,
        ];

        $task->activity_history = $history;
        $task->save();

        return '✅ Update added to <b>#'.$task->id.' '.$this->e($task->title).'</b>.'."\n".'<i>'.$this->e(Str::limit($note, 200)).'</i>';
    }

    private function findTask(User $user, ?string $rawTaskId): Task
    {
        $taskId = ltrim(trim((string) $rawTaskId), '#');

        if ($taskId === '' || ! ctype_digit($taskId)) {
            throw new InvalidArgumentException('Provide a numeric task ID, for example /task 42.');
        }

        $task = Task::query()
            ->whereKey((int) $taskId)
            ->whereHas('project', fn (Builder $query) => $query->where('user_id', $user->id))
            ->with(['project', 'assignee'])
            ->first();

        if (! $task instanceof Task) {
            throw new InvalidArgumentException('Task #'.$taskId.' was not found in your WorkOS account.');
        }

        return $task;
    }

    /**
     * @return array<int, string>
     */
    private function closedStatuses(): array
    {
        return [TaskStatus::Done->value, TaskStatus::Cancelled->value];
    }

    private function statusList(): string
    {
        return implode(', ', array_map(
            fn (TaskStatus $status): string => $status->value,
            TaskStatus::cases(),
        ));
    }

    private function e(string $value): string
    {
        return $this->telegram->escape($value);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function recordIgnored(array $update, string $reason, ?User $user = null): ?TelegramCommand
    {
        $updateId = (int) ($update['update_id'] ?? 0);
        $message = $update['message'] ?? null;

        if ($updateId <= 0 || ! is_array($message)) {
            return null;
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];

        try {
            return TelegramCommand::query()->firstOrCreate(
                ['update_id' => $updateId],
                [
                    'user_id' => $user?->id,
                    'telegram_user_id' => isset($from['id']) ? (string) $from['id'] : null,
                    'chat_id' => (string) ($chat['id'] ?? ''),
                    'command' => null,
                    'arguments' => null,
                    'payload' => $update,
                    'status' => TelegramCommand::STATUS_IGNORED,
                    'response' => $reason,
                    'processed_at' => now(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Telegram ignored command could not be recorded.', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
