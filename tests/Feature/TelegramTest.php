<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\TelegramNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private function configureTelegram(?string $chatId = null): void
    {
        config([
            'services.telegram.bot_token' => '123456:TEST-TOKEN',
            'services.telegram.chat_id' => $chatId,
            'services.telegram.enabled' => true,
            'services.telegram.api_url' => 'https://api.telegram.org',
        ]);
    }

    private function fakeTelegram(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 123456,
                    'first_name' => 'Workos',
                    'username' => 'Workosnotify_bot',
                ],
            ]),
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

    public function test_telegram_endpoints_require_authentication(): void
    {
        $this->getJson('/api/telegram/status')->assertUnauthorized();
        $this->patchJson('/api/telegram/settings', [])->assertUnauthorized();
        $this->postJson('/api/telegram/test')->assertUnauthorized();
        $this->postJson('/api/telegram/discover-chat')->assertUnauthorized();
        $this->getJson('/api/telegram/notifications')->assertUnauthorized();
    }

    public function test_user_can_save_telegram_settings(): void
    {
        $this->configureTelegram();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/telegram/settings', [
            'chat_id' => '123456789',
            'enabled' => true,
            'events' => ['task_created', 'bug_created'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.chat_id', '123456789')
            ->assertJsonPath('data.notifications_enabled', true)
            ->assertJsonPath('data.events', ['task_created', 'bug_created']);

        $this->assertSame('123456789', $user->refresh()->telegram_chat_id);
        $this->assertTrue($user->telegram_notifications_enabled);
        $this->assertSame(['task_created', 'bug_created'], $user->telegram_events);
    }

    public function test_settings_reject_an_invalid_chat_id(): void
    {
        $this->configureTelegram();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/telegram/settings', [
            'chat_id' => 'not a chat id',
        ])->assertUnprocessable()->assertJsonValidationErrors('chat_id');
    }

    public function test_enabling_notifications_without_a_chat_id_is_rejected(): void
    {
        $this->configureTelegram();
        $user = User::factory()->create([
            'telegram_chat_id' => null,
            'telegram_notifications_enabled' => false,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/telegram/settings', [
            'enabled' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('chat_id');
    }

    public function test_status_returns_bot_metadata_without_exposing_the_token(): void
    {
        $this->configureTelegram();
        $this->fakeTelegram();
        $user = User::factory()->create(['telegram_chat_id' => '987654321']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/telegram/status');

        $response->assertOk()
            ->assertJsonPath('data.bot.username', 'Workosnotify_bot')
            ->assertJsonPath('data.chat_id', '987654321');

        $this->assertArrayNotHasKey('bot_token', $response->json('data'));
        $this->assertStringNotContainsString('TEST-TOKEN', $response->getContent());
    }

    public function test_test_message_is_sent_to_the_saved_chat(): void
    {
        $this->configureTelegram();
        $this->fakeTelegram();
        $user = User::factory()->create(['telegram_chat_id' => '987654321']);
        Sanctum::actingAs($user);

        $this->postJson('/api/telegram/test')
            ->assertOk()
            ->assertJsonPath('data.chat_id', '987654321');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '987654321'
            && str_contains((string) $request['text'], 'WorkOS Telegram is connected'));
    }

    public function test_discover_chat_lists_unique_chats_seen_by_the_bot(): void
    {
        $this->configureTelegram();
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response([
                'ok' => true,
                'result' => [
                    ['update_id' => 1, 'message' => ['chat' => ['id' => 555, 'first_name' => 'Athul', 'type' => 'private']]],
                    ['update_id' => 2, 'message' => ['chat' => ['id' => 555, 'first_name' => 'Athul', 'type' => 'private']]],
                    ['update_id' => 3, 'message' => ['chat' => ['id' => -1001, 'title' => 'WorkOS Alerts', 'type' => 'supergroup']]],
                ],
            ]),
        ]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/telegram/discover-chat')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', '555')
            ->assertJsonPath('data.1.title', 'WorkOS Alerts');
    }

    public function test_discover_chat_does_not_expose_the_bot_token_on_connection_failure(): void
    {
        $this->configureTelegram();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 60 for https://api.telegram.org/bot123456:TEST-TOKEN/getUpdates'
        ));
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/telegram/discover-chat');

        $response->assertUnprocessable();
        $this->assertStringNotContainsString('TEST-TOKEN', $response->getContent());
        $this->assertStringContainsString('[redacted]', (string) $response->json('message'));
    }

    public function test_creating_a_task_sends_a_telegram_notification(): void
    {
        $this->configureTelegram();
        $this->fakeTelegram();
        $user = User::factory()->create([
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
        ]);
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', [
            'project_id' => $project->id,
            'title' => 'Ship Telegram notifications',
        ])->assertCreated();

        $this->assertDatabaseHas('telegram_notifications', [
            'user_id' => $user->id,
            'event' => 'task_created',
            'chat_id' => '999888777',
            'status' => 'sent',
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '999888777'
            && str_contains((string) $request['text'], 'Ship Telegram notifications'));
    }

    public function test_completing_a_task_sends_a_completed_notification(): void
    {
        $this->configureTelegram();
        $this->fakeTelegram();
        $user = User::factory()->create([
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
        ]);
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();
        $task = $project->tasks()->create([
            'user_id' => $user->id,
            'title' => 'Close the loop',
        ]);

        $this->patchJson("/api/tasks/{$task->id}", ['status' => 'done'])->assertOk();

        $this->assertDatabaseHas('telegram_notifications', [
            'user_id' => $user->id,
            'event' => 'task_completed',
            'status' => 'sent',
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Task completed'));
    }

    public function test_task_notification_is_skipped_when_the_event_is_disabled(): void
    {
        $this->configureTelegram();
        $this->fakeTelegram();
        $user = User::factory()->create([
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
            'telegram_events' => ['bug_created'],
        ]);
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', [
            'project_id' => $project->id,
            'title' => 'Do not announce this task',
        ])->assertCreated();

        $this->assertDatabaseCount('telegram_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_global_chat_receives_notifications_without_per_user_settings(): void
    {
        $this->configureTelegram('424242');
        $this->fakeTelegram();
        $user = User::factory()->create([
            'telegram_chat_id' => null,
            'telegram_notifications_enabled' => true,
        ]);
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', [
            'project_id' => $project->id,
            'title' => 'Global notification',
        ])->assertCreated();

        $this->assertDatabaseHas('telegram_notifications', [
            'user_id' => $user->id,
            'event' => 'task_created',
            'chat_id' => '424242',
            'status' => 'sent',
        ]);

        Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '424242');
    }

    public function test_failed_telegram_delivery_does_not_break_task_creation(): void
    {
        $this->configureTelegram();
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);
        $user = User::factory()->create([
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
        ]);
        Sanctum::actingAs($user);
        $project = Project::factory()->for($user)->create();

        $this->postJson('/api/tasks', [
            'project_id' => $project->id,
            'title' => 'Telegram is down',
        ])->assertCreated();

        $this->assertDatabaseHas('telegram_notifications', [
            'user_id' => $user->id,
            'event' => 'task_created',
            'status' => 'failed',
            'error' => 'Bad Request: chat not found',
        ]);
    }

    public function test_daily_digest_command_sends_a_summary(): void
    {
        $this->configureTelegram();
        $this->fakeTelegram();
        $user = User::factory()->create([
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
        ]);
        $project = Project::factory()->for($user)->create();
        $project->tasks()->create([
            'user_id' => $user->id,
            'title' => 'Due today',
            'due_at' => now(),
        ]);

        $this->artisan('telegram:daily-digest', ['--user' => $user->id])->assertSuccessful();

        $this->assertDatabaseHas('telegram_notifications', [
            'user_id' => $user->id,
            'event' => 'daily_digest',
            'status' => 'sent',
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'WorkOS daily digest')
            && str_contains((string) $request['text'], 'Due today'));
    }

    public function test_daily_digest_sends_a_global_summary_when_only_a_global_chat_is_configured(): void
    {
        $this->configureTelegram('424242');
        $this->fakeTelegram();

        $owner = User::factory()->create();
        $colleague = User::factory()->create();

        $ownerProject = Project::factory()->for($owner)->create();
        $ownerProject->tasks()->create([
            'user_id' => $owner->id,
            'title' => 'Owner open task',
            'due_at' => now(),
        ]);

        $colleagueProject = Project::factory()->for($colleague)->create();
        $colleagueProject->tasks()->create([
            'user_id' => $colleague->id,
            'title' => 'Colleague open task',
        ]);

        $this->artisan('telegram:daily-digest')->assertSuccessful();

        $this->assertDatabaseHas('telegram_notifications', [
            'user_id' => null,
            'event' => 'daily_digest',
            'chat_id' => '424242',
            'status' => 'sent',
        ]);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '424242'
            && str_contains((string) $request['text'], 'All users')
            && str_contains((string) $request['text'], 'Open tasks:</b> 2'));
    }

    public function test_user_can_retry_their_own_failed_notification(): void
    {
        $this->configureTelegram();
        $this->fakeTelegram();
        $user = User::factory()->create([
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
        ]);
        Sanctum::actingAs($user);
        $notification = TelegramNotification::create([
            'user_id' => $user->id,
            'event' => 'task_created',
            'chat_id' => '999888777',
            'message' => 'Retry me',
            'status' => 'failed',
            'error' => 'Previous failure',
        ]);

        $this->postJson("/api/telegram/notifications/{$notification->id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertSame('sent', $notification->refresh()->status);
        $this->assertNull($notification->error);
    }
}
