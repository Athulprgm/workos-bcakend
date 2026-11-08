<?php

namespace Tests\Feature\Api;

use App\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_generate_and_save_real_database_work_report(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'API Gateway',
            'slug' => 'api-gateway',
        ]);

        $completedTask = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Implement JWT Auth',
            'status' => TaskStatus::Done->value,
            'completed_at' => now(),
        ]);

        $inProgressTask = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Rate limiter Redis backend',
            'status' => TaskStatus::InProgress->value,
        ]);

        $user->workLogs()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'work_description' => 'Configured Redis cluster connection pool',
            'date' => today(),
            'time_spent_minutes' => 75,
            'problems_encountered' => 'Socket timeout during bench test',
        ]);

        // Generate report preview
        $genResponse = $this->postJson('/api/work-reports/generate', [
            'workspace_id' => $workspace->id,
            'type' => 'daily',
        ])->assertOk();

        $reportData = $genResponse->json('data');
        $this->assertSame('Acme Corporation', $reportData['company_name']);
        $this->assertSame(75, $reportData['time_spent_minutes']);
        $this->assertNotEmpty($reportData['completed_work']);
        $this->assertNotEmpty($reportData['work_in_progress']);
        $this->assertNotEmpty($reportData['raw_content']);
        $this->assertNotEmpty($reportData['whatsapp_content']);

        // Save report
        $saveResponse = $this->postJson('/api/work-reports', [
            'workspace_id' => $workspace->id,
            'type' => 'daily',
            'period_start' => today()->toDateString(),
            'period_end' => today()->toDateString(),
            'title' => $reportData['title'],
            'company_name' => $reportData['company_name'],
            'raw_content' => $reportData['raw_content'],
            'time_spent_minutes' => 75,
        ])->assertCreated();

        $savedId = $saveResponse->json('data.id');
        $this->assertDatabaseHas('work_reports', ['id' => $savedId]);
    }
}
