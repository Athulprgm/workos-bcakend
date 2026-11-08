<?php

use App\Http\Controllers\Api\AiMemoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BugController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GithubController;
use App\Http\Controllers\Api\GithubLinkController;
use App\Http\Controllers\Api\GithubWebhookController;
use App\Http\Controllers\Api\GoogleOAuthController;
use App\Http\Controllers\Api\GoogleSheetConnectionController;
use App\Http\Controllers\Api\JujuAssistantController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskDependencyController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TelegramController;
use App\Http\Controllers\Api\TesterInvitationController;
use App\Http\Controllers\Api\WorkLogController;
use App\Http\Controllers\Api\WorkReportController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Public GitHub callback and webhook endpoints
Route::get('github/callback', [GithubController::class, 'callback'])->name('github.callback');
Route::post('github/webhook', [GithubWebhookController::class, 'handle'])->name('github.webhook');

// Public Telegram webhook endpoint
Route::post('telegram/webhook', [TelegramController::class, 'webhook'])->name('telegram.webhook');

// Public Google OAuth callback (state is verified server-side)
Route::get('google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');

// Public Work Report view for staff / clients (no auth required)
Route::get('public/work-reports', [WorkReportController::class, 'publicIndex'])->name('work-reports.public-index');
Route::get('public/work-reports/{token}', [WorkReportController::class, 'publicShow'])->name('work-reports.public-show');

Route::post('ai/tts', [JujuAssistantController::class, 'textToSpeech'])->name('ai.tts');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('register');

    // Credential attempts are throttled per email + IP to slow brute forcing.
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    Route::post('tester-login', [AuthController::class, 'testerLogin'])
        ->middleware('throttle:login')
        ->name('tester-login');

    Route::middleware(['auth:sanctum', 'tester.scope'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
    });
});

Route::middleware(['auth:sanctum', 'tester.scope'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Multi-company workspace management
    Route::apiResource('workspaces', WorkspaceController::class);

    // Projects and daily tasks
    Route::apiResource('projects', ProjectController::class);
    Route::apiResource('tasks', TaskController::class);

    Route::post('tasks/{task}/dependencies', [TaskDependencyController::class, 'store'])
        ->name('tasks.dependencies.store');
    Route::delete('tasks/{task}/dependencies/{dependency}', [TaskDependencyController::class, 'destroy'])
        ->name('tasks.dependencies.destroy');

    // Work logs
    Route::get('work-logs/stats', [WorkLogController::class, 'stats'])->name('work-logs.stats');
    Route::apiResource('work-logs', WorkLogController::class);

    // Work reports, visibility and General Manager sharing
    Route::get('work-reports/general-managers', [WorkReportController::class, 'generalManagers'])
        ->name('work-reports.general-managers');
    Route::post('work-reports/generate', [WorkReportController::class, 'generate'])->name('work-reports.generate');
    Route::apiResource('work-reports', WorkReportController::class);

    // Juju AI Assistant & AI Model Configuration
    Route::post('juju/chat', [JujuAssistantController::class, 'chat'])->name('juju.chat');
    Route::get('ai/live-token', [JujuAssistantController::class, 'getLiveToken'])->name('ai.live-token');
    Route::get('ai/status', [JujuAssistantController::class, 'aiStatus'])->name('ai.status');
    Route::post('ai/config', [JujuAssistantController::class, 'updateAiConfig'])->name('ai.config');

    // AI Long-term Memory & User Preferences (User-Controlled Memory Management)
    Route::get('ai/memories/summary', [AiMemoryController::class, 'summary'])->name('ai.memories.summary');
    Route::delete('ai/memories/clear', [AiMemoryController::class, 'clear'])->name('ai.memories.clear');
    Route::apiResource('ai/memories', AiMemoryController::class);

    Route::get('tags', [TagController::class, 'index'])->name('tags.index');

    // Telegram notifications and connection diagnostics
    Route::prefix('telegram')->name('telegram.')->group(function () {
        Route::get('status', [TelegramController::class, 'status'])->name('status');
        Route::patch('settings', [TelegramController::class, 'update'])->name('settings');
        Route::post('test', [TelegramController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::post('discover-chat', [TelegramController::class, 'discoverChat'])->middleware('throttle:10,1')->name('discover-chat');
        Route::get('notifications', [TelegramController::class, 'notifications'])->name('notifications.index');
        Route::post('notifications/{notification}/retry', [TelegramController::class, 'retry'])->middleware('throttle:10,1')->name('notifications.retry');
    });

    // Testing module: owners manage invitations; testers are locked to these routes.
    Route::prefix('testing')->name('testing.')->group(function () {
        Route::get('projects', [BugController::class, 'projects'])->name('projects');
        Route::get('bugs/stats', [BugController::class, 'stats'])->name('bugs.stats');
        Route::apiResource('bugs', BugController::class);

        Route::get('invitations', [TesterInvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [TesterInvitationController::class, 'store'])->name('invitations.store');
        Route::patch('invitations/{invitation}', [TesterInvitationController::class, 'update'])->name('invitations.update');
        Route::delete('invitations/{invitation}', [TesterInvitationController::class, 'destroy'])->name('invitations.destroy');
        Route::post('invitations/{invitation}/revoke', [TesterInvitationController::class, 'revoke'])->name('invitations.revoke');
        Route::post('invitations/{invitation}/rotate-key', [TesterInvitationController::class, 'rotateKey'])->name('invitations.rotate-key');
    });

    // Super Admin-only team and role management.
    Route::middleware('super.admin')->prefix('team')->name('team.')->group(function () {
        Route::get('members', [TeamController::class, 'index'])->name('members.index');
        Route::post('members', [TeamController::class, 'store'])->name('members.store');
        Route::patch('members/{member}', [TeamController::class, 'update'])->name('members.update');
        Route::delete('members/{member}', [TeamController::class, 'destroy'])->name('members.destroy');
        Route::post('members/{member}/tester-key', [TeamController::class, 'rotateTesterKey'])->name('members.tester-key');
    });

    // Google Sheets OAuth and sync management
    Route::prefix('google')->name('google.')->group(function () {
        Route::get('connect', [GoogleOAuthController::class, 'connect'])->name('connect');
        Route::get('status', [GoogleOAuthController::class, 'status'])->name('status');
        Route::post('disconnect', [GoogleOAuthController::class, 'disconnect'])->name('disconnect');
    });

    Route::prefix('google-sheets')->name('google-sheets.')->group(function () {
        Route::post('preview', [GoogleSheetConnectionController::class, 'preview'])->name('preview');
        Route::get('connections', [GoogleSheetConnectionController::class, 'index'])->name('connections.index');
        Route::post('connections', [GoogleSheetConnectionController::class, 'store'])->name('connections.store');
        Route::get('connections/{connection}', [GoogleSheetConnectionController::class, 'show'])->name('connections.show');
        Route::patch('connections/{connection}', [GoogleSheetConnectionController::class, 'update'])->name('connections.update');
        Route::delete('connections/{connection}', [GoogleSheetConnectionController::class, 'destroy'])->name('connections.destroy');
        Route::post('connections/{connection}/sync', [GoogleSheetConnectionController::class, 'sync'])->name('connections.sync');
        Route::get('connections/{connection}/conflicts', [GoogleSheetConnectionController::class, 'conflicts'])->name('connections.conflicts');
        Route::post('connections/{connection}/conflicts/{conflict}/resolve', [GoogleSheetConnectionController::class, 'resolveConflict'])->name('connections.conflicts.resolve');
    });

    // Authenticated GitHub integration endpoints
    Route::prefix('github')->name('github.')->group(function () {
        Route::get('connect', [GithubController::class, 'connect'])->name('connect');
        Route::get('status', [GithubController::class, 'status'])->name('status');
        Route::post('disconnect', [GithubController::class, 'disconnect'])->name('disconnect');
        Route::get('repositories', [GithubController::class, 'repositories'])->name('repositories');
        Route::get('repositories/{owner}/{repo}/commits', [GithubController::class, 'commits'])->name('commits');
        Route::get('repositories/{owner}/{repo}/branches', [GithubController::class, 'branches'])->name('branches');
        Route::get('repositories/{owner}/{repo}/pull-requests', [GithubController::class, 'pullRequests'])->name('pull-requests');
        Route::get('repositories/{owner}/{repo}/issues', [GithubController::class, 'issues'])->name('issues');
        Route::get('webhook-events', [GithubWebhookController::class, 'events'])->name('webhook.events');

        // Repository ↔ Work OS project links and issue/PR associations
        Route::get('links', [GithubLinkController::class, 'index'])->name('links.index');
        Route::post('links', [GithubLinkController::class, 'store'])->name('links.store');
        Route::patch('links/{link}', [GithubLinkController::class, 'update'])->name('links.update');
        Route::delete('links/{link}', [GithubLinkController::class, 'destroy'])->name('links.destroy');
        Route::get('links/{link}/issues', [GithubLinkController::class, 'issues'])->name('links.issues');
        Route::get('linked-items', [GithubLinkController::class, 'linkedItems'])->name('linked-items');
        Route::post('links/{link}/items', [GithubLinkController::class, 'linkItem'])->name('links.items.store');
        Route::delete('links/{link}/items/{githubLink}', [GithubLinkController::class, 'unlinkItem'])->name('links.items.destroy');
    });
});
