<?php

namespace Tests\Feature\Api;

use App\Models\Bug;
use App\Models\GoogleAccount;
use App\Models\GoogleSheetConnection;
use App\Models\Project;
use App\Models\SheetSyncConflict;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleSheetsTest extends TestCase
{
    use RefreshDatabase;

    private function projectFor(User $user, string $name = 'Alpha'): Project
    {
        $workspace = $user->workspaces()->firstOrCreate(
            ['slug' => 'workspace'],
            ['name' => 'Workspace'],
        );

        return $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
        ]);
    }

    private function googleAccount(User $user): GoogleAccount
    {
        return GoogleAccount::create([
            'user_id' => $user->id,
            'google_user_id' => '123',
            'email' => 'owner@example.com',
            'access_token' => 'google-access-token',
            'refresh_token' => 'google-refresh-token',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/spreadsheets'],
        ]);
    }

    private function connection(User $owner, array $overrides = []): GoogleSheetConnection
    {
        $project = $this->projectFor($owner);

        return GoogleSheetConnection::create(array_merge([
            'user_id' => $owner->id,
            'google_account_id' => $this->googleAccount($owner)->id,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'spreadsheet_id' => 'sheet123',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/sheet123/edit',
            'sheet_name' => 'Bugs',
            'header_row' => 1,
            'sync_enabled' => true,
            'bidirectional' => false,
            'conflict_policy' => 'manual',
        ], $overrides));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rows(string $title = 'Login fails', string $status = 'Open', string $updated = '2026-10-20 10:00'): array
    {
        return [
            ['Bug ID', 'Number', 'Project Name', 'Bug Title', 'Bug Details', 'Priority', 'Status', 'Tester', 'Created Date', 'Updated Date', 'Testing Notes', 'Validated'],
            ['BUG-0001', '1', 'Alpha', $title, 'Crash on login.', 'Critical', $status, 'Tess Tester', '2026-10-19', $updated, 'Reproduced on Chrome.', 'no'],
            ['BUG-0002', '2', 'Alpha', 'Slow dashboard', 'Dashboard takes 10s.', 'Low', 'Fixed', '', '2026-10-19', $updated, '', 'yes'],
        ];
    }

    public function test_preview_validates_spreadsheet_access_and_url(): void
    {
        $user = User::factory()->create();
        $this->googleAccount($user);
        Sanctum::actingAs($user);

        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet123*' => Http::response([
                'properties' => ['title' => 'QA Tracker'],
                'sheets' => [
                    ['properties' => ['title' => 'Bugs']],
                    ['properties' => ['title' => 'Archive']],
                ],
            ], 200),
        ]);

        $this->postJson('/api/google-sheets/preview', [
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/sheet123/edit#gid=0',
        ])->assertOk()
            ->assertJsonPath('data.spreadsheet_id', 'sheet123')
            ->assertJsonPath('data.title', 'QA Tracker')
            ->assertJsonPath('data.sheets.0', 'Bugs');

        $this->postJson('/api/google-sheets/preview', [
            'spreadsheet_url' => 'https://example.com/not-a-sheet',
        ])->assertUnprocessable()->assertJsonValidationErrors('spreadsheet_url');
    }

    public function test_preview_refuses_a_spreadsheet_the_account_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->googleAccount($user);
        Sanctum::actingAs($user);

        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/private*' => Http::response(['error' => ['message' => 'Forbidden']], 403),
        ]);

        $this->postJson('/api/google-sheets/preview', [
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/private/edit',
        ])->assertUnprocessable()->assertJsonValidationErrors('spreadsheet_url');
    }

    public function test_sync_imports_rows_and_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $connection = $this->connection($owner);
        Sanctum::actingAs($owner);

        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet123/values/*' => Http::sequence()
                ->push(['values' => $this->rows()], 200)
                ->push(['values' => $this->rows()], 200),
        ]);

        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/sync')
            ->assertOk()
            ->assertJsonPath('summary.created', 2);

        $this->assertDatabaseCount('bugs', 2);

        $bug = Bug::where('number', 1)->firstOrFail();
        $this->assertSame('Login fails', $bug->title);
        $this->assertSame('critical', $bug->priority->value);
        $this->assertSame('google_sheets', $bug->source->value);
        $this->assertSame('Tess Tester', $bug->tester_name);
        $this->assertFalse($bug->validated);

        $validatedBug = Bug::where('number', 2)->firstOrFail();
        $this->assertTrue($validatedBug->validated);

        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/sync')
            ->assertOk()
            ->assertJsonPath('summary.created', 0)
            ->assertJsonPath('summary.updated', 0);

        $this->assertDatabaseCount('bugs', 2);
        $this->assertSame(1, Bug::where('number', 1)->count());
    }

    public function test_sync_detects_conflicting_local_and_sheet_edits_and_can_take_remote(): void
    {
        $owner = User::factory()->create();
        $connection = $this->connection($owner);
        Sanctum::actingAs($owner);

        Http::fake([
            'https://sheets.googleapis.com/v4/spreadsheets/sheet123/values/*' => Http::sequence()
                ->push(['values' => $this->rows()], 200)
                ->push(['values' => $this->rows('Remote title', 'In Progress', '2026-10-25 10:00')], 200),
        ]);

        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/sync')->assertOk();

        $bug = Bug::where('number', 1)->firstOrFail();
        $connection->update(['last_synced_at' => now()->subHour()]);
        $bug->update(['title' => 'Local title']);

        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/sync')
            ->assertOk()
            ->assertJsonPath('summary.conflicts', 1);

        $this->assertSame('Local title', $bug->fresh()->title);
        $this->assertDatabaseCount('sheet_sync_conflicts', 1);

        $conflict = SheetSyncConflict::firstOrFail();
        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/conflicts/'.$conflict->id.'/resolve', [
            'resolution' => 'remote',
        ])->assertOk();

        $this->assertSame('Remote title', $bug->fresh()->title);
        $this->assertSame('in_progress', $bug->fresh()->status->value);
        $this->assertSame('resolved', $conflict->fresh()->status);
    }

    public function test_bidirectional_sync_exports_a_local_bug_once(): void
    {
        $owner = User::factory()->create();
        $connection = $this->connection($owner, ['bidirectional' => true]);
        $project = $connection->project;

        $bug = Bug::create([
            'project_id' => $project->id,
            'number' => 1,
            'title' => 'Local-only bug',
            'details' => 'Found while testing.',
            'priority' => 'high',
            'status' => 'open',
            'created_by' => $owner->id,
        ]);

        Sanctum::actingAs($owner);

        $sheetRows = [['Bug ID', 'Number', 'Project Name', 'Bug Title', 'Bug Details', 'Priority', 'Status', 'Tester', 'Created Date', 'Updated Date', 'Testing Notes', 'Validated']];

        Http::fake(function (ClientRequest $request) use (&$sheetRows) {
            if ($request->method() === 'GET') {
                return Http::response(['values' => $sheetRows], 200);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), ':append')) {
                $values = $request->data()['values'][0] ?? [];
                $sheetRows[] = $values;

                return Http::response(['updates' => ['updatedRange' => "'Bugs'!A2:L2"]], 200);
            }

            return Http::response(['values' => $sheetRows], 200);
        });

        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/sync')
            ->assertOk()
            ->assertJsonPath('summary.exported', 1);

        $this->assertNotNull($bug->fresh()->external_key);
        $this->assertCount(2, $sheetRows);
        $this->assertSame('Local-only bug', $sheetRows[1][3]);

        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/sync')
            ->assertOk()
            ->assertJsonPath('summary.exported', 0);

        $this->assertCount(2, $sheetRows);
    }

    public function test_another_user_cannot_read_or_sync_the_connection(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $connection = $this->connection($owner);

        Sanctum::actingAs($outsider);

        $this->postJson('/api/google-sheets/connections/'.$connection->id.'/sync')->assertForbidden();
        $this->getJson('/api/google-sheets/connections/'.$connection->id)->assertForbidden();
        $this->deleteJson('/api/google-sheets/connections/'.$connection->id)->assertForbidden();
    }
}
