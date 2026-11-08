<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TelegramCommand;
use App\Models\User;
use App\Services\TelegramCommandHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const TELEGRAM_USER_ID = '8589557534';

    private const TELEGRAM_CHAT_ID = '8589557534';

    private const OTHER_TELEGRAM_ID = '1111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.chat_id' => self::TELEGRAM_CHAT_ID,
            'services.telegram.enabled' => true,
            'services.telegram.api_url' => 'https://api.telegram.org',
            'services.telegram.allowed_user_ids' => [self::TELEGRAM_USER_ID],
            'services.telegram.poll_timeout' => 1,
        ]);

        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function linkUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Telegram Owner',
            'telegram_chat_id' => self::TELEGRAM_CHAT_ID,
            'telegram_notifications_enabled' => true,
            'telegram_events' => null,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTask(User $user, array $attributes = []): Task
    {
        $workspace = $user->workspaces()->create([
            'name' => 'Telegram Test Workspace',
            'slug' => 'telegram-test-'.Str::lower(Str::random(8)),
            'company_type' => 'my_company',
        ]);

        $project = Project::factory()->for($user)->create([
            'workspace_id' => $workspace->id,
            'name' => 'Telegram Test Project',
        ]);

        return $project->tasks()->create(array_merge([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Fix the Telegram integration',
            'status' => TaskStatus::Todo->value,
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramUpdate(
        string $text,
        int $updateId,
        ?string $fromId = null,
        ?string $chatId = null,
        string $chatType = 'private',
    ): array {
        $fromId ??= self::TELEGRAM_USER_ID;
        $chatId ??= $fromId;

        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => [
                    'id' => (int) $fromId,
                    'is_bot' => false,
                    'first_name' => 'Telegram',
                ],
                'chat' => [
                    'id' => (int) $chatId,
                    'type' => $chatType,
                ],
                'date' => now()->timestamp,
                'text' => $text,
            ],
        ];
    }

    private function handle(string $text, int $updateId = 100): ?TelegramCommand
    {
        return app(TelegramCommandHandler::class)->handle($this->telegramUpdate($text, $updateId));
    }

    private function fakeTelegram(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 42],
            ]),
            'api.telegram.org/*/getUpdates*' => Http::response([
                'ok' => true,
                'result' => [],
            ]),
        ]);
    }

    public function test_authorized_help_command_replies_in_a_private_chat(): void
    {
        $user = $this->linkUser();
        $this->fakeTelegram();

        $command = $this->handle('/help', 101);

        $this->assertInstanceOf(TelegramCommand::class, $command);
        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command->status);
        $this->assertDatabaseHas('telegram_commands', [
            'update_id' => 101,
            'user_id' => $user->id,
            'command' => 'help',
            'status' => TelegramCommand::STATUS_PROCESSED,
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === self::TELEGRAM_CHAT_ID
            && str_contains((string) $request['text'], 'WorkOS Telegram commands')
            && str_contains((string) $request['text'], 'Telegram Owner'));
    }

    public function test_unlisted_telegram_users_cannot_run_commands(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();

        $command = app(TelegramCommandHandler::class)->handle(
            $this->telegramUpdate('/done '.$task->id, 102, self::OTHER_TELEGRAM_ID),
        );

        $this->assertNull($command);
        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);
        $this->assertDatabaseCount('telegram_commands', 0);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'not authorized'));
    }

    public function test_tasks_command_lists_only_the_users_open_tasks(): void
    {
        $user = $this->linkUser();
        $openTask = $this->createTask($user, ['title' => 'Owner open task']);
        $doneTask = $this->createTask($user, [
            'title' => 'Owner completed task',
            'status' => TaskStatus::Done->value,
        ]);
        $otherUser = User::factory()->create();
        $this->createTask($otherUser, ['title' => 'Other user task']);
        $this->fakeTelegram();

        $command = $this->handle('/tasks', 103);

        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command?->status);
        $this->assertDatabaseHas('telegram_commands', [
            'update_id' => 103,
            'status' => TelegramCommand::STATUS_PROCESSED,
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], $openTask->title)
            && ! str_contains((string) $request['text'], $doneTask->title)
            && ! str_contains((string) $request['text'], 'Other user task'));
    }

    public function test_tasks_command_can_list_only_completed_tasks(): void
    {
        $user = $this->linkUser();
        $openTask = $this->createTask($user, ['title' => 'Still open task']);
        $doneTask = $this->createTask($user, [
            'title' => 'Finished task',
            'status' => TaskStatus::Done->value,
        ]);
        $this->fakeTelegram();

        $command = $this->handle('/tasks done', 104);

        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command?->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], $doneTask->title)
            && ! str_contains((string) $request['text'], $openTask->title));
    }

    public function test_task_command_shows_task_details(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user, [
            'title' => 'Prepare release notes',
            'description' => 'Summarize the changes for the release.',
        ]);
        $this->fakeTelegram();

        $command = $this->handle('/task '.$task->id, 105);

        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command?->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '#'.$task->id.' Prepare release notes')
            && str_contains((string) $request['text'], 'Summarize the changes for the release.'));
    }

    public function test_status_command_updates_the_task_and_replies(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();

        $command = $this->handle('/status@Workosnotify_bot '.$task->id.' in_progress', 106);

        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command?->status);
        $task->refresh();
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertSame('status_change', $task->activity_history[0]['event']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'is now')
            && str_contains((string) $request['text'], 'In Progress'));
    }

    public function test_done_command_marks_the_task_completed(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();

        $command = $this->handle('/done '.$task->id, 107);

        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command?->status);
        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertNotNull($task->completed_at);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'is now')
            && str_contains((string) $request['text'], 'Done'));
    }

    public function test_log_command_creates_a_work_log(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();

        $command = $this->handle('/log '.$task->id.' 45 Fixed the login bug', 108);

        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command?->status);
        $this->assertDatabaseHas('work_logs', [
            'user_id' => $user->id,
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'work_description' => 'Fixed the login bug',
            'time_spent_minutes' => 45,
        ]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '45m')
            && str_contains((string) $request['text'], 'Fixed the login bug'));
    }

    public function test_note_command_appends_a_work_update(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();

        $command = $this->handle('/note '.$task->id.' Waiting on QA review', 109);

        $this->assertSame(TelegramCommand::STATUS_PROCESSED, $command?->status);
        $task->refresh();
        $this->assertSame('telegram_update', $task->activity_history[0]['event']);
        $this->assertSame('Waiting on QA review', $task->activity_history[0]['description']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Update added')
            && str_contains((string) $request['text'], 'Waiting on QA review'));
    }

    public function test_task_commands_cannot_access_another_users_task(): void
    {
        $this->linkUser();
        $otherUser = User::factory()->create();
        $otherTask = $this->createTask($otherUser);
        $this->fakeTelegram();

        $command = $this->handle('/done '.$otherTask->id, 110);

        $this->assertSame(TelegramCommand::STATUS_FAILED, $command?->status);
        $this->assertSame(TaskStatus::Todo, $otherTask->refresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'was not found'));
    }

    public function test_invalid_status_replies_with_an_error_without_changing_the_task(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();

        $command = $this->handle('/status '.$task->id.' doing', 111);

        $this->assertSame(TelegramCommand::STATUS_FAILED, $command?->status);
        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Unknown status'));
    }

    public function test_duplicate_updates_are_processed_once(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();
        $handler = app(TelegramCommandHandler::class);
        $update = $this->telegramUpdate('/done '.$task->id, 112);

        $handler->handle($update);
        $handler->handle($update);

        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
        $this->assertDatabaseCount('telegram_commands', 1);
        Http::assertSentCount(1);
    }

    public function test_commands_are_ignored_in_group_chats(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);
        $this->fakeTelegram();

        $command = app(TelegramCommandHandler::class)->handle(
            $this->telegramUpdate(
                '/done '.$task->id,
                113,
                self::TELEGRAM_USER_ID,
                '-1001234567890',
                'supergroup',
            ),
        );

        $this->assertSame(TelegramCommand::STATUS_IGNORED, $command?->status);
        $this->assertSame(TaskStatus::Todo, $task->refresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-1001234567890'
            && str_contains((string) $request['text'], 'private chat'));
    }

    public function test_commands_from_unlinked_chats_are_rejected(): void
    {
        User::factory()->create();
        $this->fakeTelegram();

        $command = $this->handle('/help', 114);

        $this->assertSame(TelegramCommand::STATUS_IGNORED, $command?->status);
        $this->assertNull($command?->user_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'not linked'));
    }

    public function test_command_rate_limit_blocks_excessive_commands(): void
    {
        $user = $this->linkUser();
        $this->fakeTelegram();
        $rateLimitKey = 'telegram-command:'.$user->id;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        $command = $this->handle('/help', 115);

        $this->assertSame(TelegramCommand::STATUS_IGNORED, $command?->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Too many commands'));
    }

    public function test_command_replies_escape_user_controlled_html(): void
    {
        $user = $this->linkUser(['name' => '<b>Owner</b>']);
        $task = $this->createTask($user, ['title' => '<script>alert(1)</script>']);
        $this->fakeTelegram();
        $handler = app(TelegramCommandHandler::class);

        $handler->handle($this->telegramUpdate('/tasks', 116));
        $handler->handle($this->telegramUpdate('/note '.$task->id.' <i>release</i>', 117));
        $handler->handle($this->telegramUpdate('/help', 118));

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], '&lt;script&gt;alert(1)&lt;/script&gt;')
            && ! str_contains((string) $request['text'], '<script>alert(1)</script>'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], '&lt;i&gt;release&lt;/i&gt;'));
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], '&lt;b&gt;Owner&lt;/b&gt;'));
    }

    public function test_poller_processes_a_telegram_update_and_advances_the_offset(): void
    {
        $user = $this->linkUser();
        $task = $this->createTask($user);

        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response([
                'ok' => true,
                'result' => [$this->telegramUpdate('/done '.$task->id, 9001)],
            ]),
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 43],
            ]),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();

        $this->assertSame(TaskStatus::Done, $task->refresh()->status);
        $this->assertDatabaseHas('telegram_commands', [
            'update_id' => 9001,
            'status' => TelegramCommand::STATUS_PROCESSED,
        ]);
        $this->assertSame(9001, (int) Cache::get('workos.telegram.last_update_id'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/getUpdates')
            && (int) $request['offset'] === 0);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'is now'));
    }

    public function test_poller_does_not_start_when_another_poller_holds_the_lease(): void
    {
        Cache::put('workos.telegram.poller', 'another-poller', 120);
        Http::preventStrayRequests();

        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutputToContain('Another Telegram poller is already running.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_poller_fails_cleanly_when_telegram_rejects_get_updates(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response([
                'ok' => false,
                'description' => "Conflict: can't use getUpdates method while webhook is active",
            ], 409),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])
            ->expectsOutputToContain('webhook')
            ->assertFailed();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/getUpdates'));
    }
}
