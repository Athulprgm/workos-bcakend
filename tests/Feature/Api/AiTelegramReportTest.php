<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\TelegramCommand;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkReport;
use App\Models\Workspace;
use App\Services\AiTelegramReportService;
use App\Services\GeminiService;
use App\Services\TelegramCommandHandler;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiTelegramReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'admin',
            'telegram_chat_id' => '8589557534',
            'telegram_notifications_enabled' => true,
        ]);

        config(['services.telegram.allowed_user_ids' => ['8589557534']]);

        $this->workspace = $this->user->workspaces()->create([
            'name' => 'WorkOS Core',
            'slug' => 'workos-core',
        ]);

        $this->project = Project::create([
            'user_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'name' => 'Deposit Commission Management',
            'description' => 'Deposit commission calculation and payouts',
            'status' => 'active',
        ]);
    }

    public function test_telegram_natural_language_message_creates_daily_report_and_work_log(): void
    {
        $mockGemini = $this->createMock(GeminiService::class);
        $mockGemini->method('isConfigured')->willReturn(true);
        $mockGemini->method('generateJson')->willReturn([
            'is_edit_request' => false,
            'reports' => [
                [
                    'date' => Carbon::now('Asia/Kolkata')->toDateString(),
                    'matched_project_id' => $this->project->id,
                    'project_name' => 'Deposit Commission Management',
                    'title' => 'Design Bug Fixes and Commission Logic Updates',
                    'summary' => 'Fixed design inconsistencies and corrected commission calculation logic.',
                    'description' => 'Reviewed the existing deposit commission module, identified UI inconsistencies and logical errors, implemented the necessary corrections, and tested the affected workflows.',
                    'status' => 'Completed',
                    'time_spent_minutes' => 120,
                    'next_steps' => 'Deploy to staging',
                ],
            ],
        ]);
        $this->app->instance(GeminiService::class, $mockGemini);

        $sentMessages = [];
        $mockTelegram = $this->createMock(TelegramService::class);
        $mockTelegram->method('isEnabled')->willReturn(true);
        $mockTelegram->method('sendMessage')->willReturnCallback(function ($chatId, $text, $options = []) use (&$sentMessages) {
            $sentMessages[] = ['chat_id' => $chatId, 'text' => $text, 'options' => $options];
            return ['ok' => true, 'message_id' => 1234];
        });
        $this->app->instance(TelegramService::class, $mockTelegram);

        $updateId = 987654;
        $response = $this->postJson(route('telegram.webhook'), [
            'update_id' => $updateId,
            'message' => [
                'message_id' => 111,
                'from' => ['id' => '8589557534', 'is_bot' => false],
                'chat' => ['id' => '8589557534', 'type' => 'private'],
                'text' => 'Today fixed design bugs and logical errors in deposit commission management and completed workflow testing.',
            ],
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        // Verify WorkLog was created
        $this->assertDatabaseHas('work_logs', [
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
        ]);
        $this->assertEquals(Carbon::now('Asia/Kolkata')->toDateString(), WorkLog::first()->date->toDateString());

        // Verify WorkReport was created
        $this->assertDatabaseHas('work_reports', [
            'user_id' => $this->user->id,
            'type' => 'daily',
        ]);
        $this->assertEquals(Carbon::now('Asia/Kolkata')->toDateString(), WorkReport::first()->period_end->toDateString());

        // Verify Telegram message was delivered with buttons
        $this->assertCount(1, $sentMessages);
        $this->assertStringContainsString('✅ <b>Daily Report Generated</b>', $sentMessages[0]['text']);
        $this->assertStringContainsString('Deposit Commission Management', $sentMessages[0]['text']);
        $this->assertStringContainsString('Completed', $sentMessages[0]['text']);
        $this->assertArrayHasKey('reply_markup', $sentMessages[0]['options']);
    }

    public function test_telegram_natural_language_edit_updates_existing_report(): void
    {
        $now = Carbon::now('Asia/Kolkata')->toDateString();

        $report = WorkReport::create([
            'user_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'type' => 'daily',
            'period_start' => $now,
            'period_end' => $now,
            'title' => "Daily Work Report: Deposit Commission Management",
            'company_name' => $this->workspace->name,
            'visibility' => \App\Enums\ReportVisibility::Public,
            'projects_worked' => [['id' => $this->project->id, 'name' => $this->project->name]],
            'work_in_progress' => [['title' => 'Initial Task', 'project' => $this->project->name]],
        ]);

        $mockGemini = $this->createMock(GeminiService::class);
        $mockGemini->method('isConfigured')->willReturn(true);
        $mockGemini->method('generateJson')->willReturn([
            'is_edit_request' => true,
            'target_report_id' => $report->id,
            'reports' => [
                [
                    'date' => $now,
                    'title' => 'Updated Deliverable Title',
                    'summary' => 'Updated status to Completed',
                    'description' => 'Tested and verified all features.',
                    'status' => 'Completed',
                ],
            ],
        ]);
        $this->app->instance(GeminiService::class, $mockGemini);

        $sentMessages = [];
        $mockTelegram = $this->createMock(TelegramService::class);
        $mockTelegram->method('isEnabled')->willReturn(true);
        $mockTelegram->method('sendMessage')->willReturnCallback(function ($chatId, $text, $options = []) use (&$sentMessages) {
            $sentMessages[] = ['chat_id' => $chatId, 'text' => $text, 'options' => $options];
            return ['ok' => true, 'message_id' => 1235];
        });
        $this->app->instance(TelegramService::class, $mockTelegram);

        $response = $this->postJson(route('telegram.webhook'), [
            'update_id' => 987655,
            'message' => [
                'message_id' => 112,
                'from' => ['id' => '8589557534', 'is_bot' => false],
                'chat' => ['id' => '8589557534', 'type' => 'private'],
                'text' => 'Change status to Completed',
            ],
        ]);

        $response->assertOk();

        // Check report was updated in-place
        $report->refresh();
        $this->assertNotEmpty($report->completed_work);
        $this->assertEmpty($report->work_in_progress);
        $this->assertStringContainsString('✅ <b>Daily Report Updated</b>', $sentMessages[0]['text']);
        $this->assertStringContainsString('Completed', $sentMessages[0]['text']);
    }
}
