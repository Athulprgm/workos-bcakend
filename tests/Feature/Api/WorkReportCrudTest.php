<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\WorkReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkReportCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_report_with_clean_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $response = $this->postJson('/api/work-reports', [
            'workspace_id' => $workspace->id,
            'date' => '2026-11-07',
            'project_name' => 'Website Redesign',
            'work_description' => 'Implemented new reports table and navigation.',
            'status' => 'Completed',
        ])->assertCreated();

        $data = $response->json('data');
        $this->assertSame('Website Redesign', $data['project_name']);
        $this->assertSame('Implemented new reports table and navigation.', $data['work_description']);
        $this->assertSame('Completed', $data['status']);
        $this->assertSame('2026-11-07', $data['date']);

        $reportId = $data['id'];
        $this->assertDatabaseHas('work_reports', [
            'id' => $reportId,
            'project_name' => 'Website Redesign',
            'status' => 'Completed',
        ]);

        // Test update
        $updateResponse = $this->patchJson("/api/work-reports/{$reportId}", [
            'project_name' => 'Website Redesign V2',
            'work_description' => 'Updated work description for verification.',
            'status' => 'In Progress',
            'date' => '2026-11-08',
        ])->assertOk();

        $updatedData = $updateResponse->json('data');
        $this->assertSame('Website Redesign V2', $updatedData['project_name']);
        $this->assertSame('Updated work description for verification.', $updatedData['work_description']);
        $this->assertSame('In Progress', $updatedData['status']);
        $this->assertSame('2026-11-08', $updatedData['date']);

        // Test filtering
        $this->getJson('/api/work-reports?status=In Progress')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/work-reports?status=Completed')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/work-reports?q=Redesign')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Test delete
        $this->deleteJson("/api/work-reports/{$reportId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('work_reports', ['id' => $reportId]);

        // Test with Complete and Progress aliases
        $aliasResponse = $this->postJson('/api/work-reports', [
            'workspace_id' => $workspace->id,
            'date' => '2026-11-09',
            'project_name' => 'Mobile App',
            'work_description' => 'Tested push notifications',
            'status' => 'Progress',
        ])->assertCreated();

        $this->assertSame('In Progress', $aliasResponse->json('data.status'));

        $completeResponse = $this->patchJson('/api/work-reports/'.$aliasResponse->json('data.id'), [
            'status' => 'Complete',
        ])->assertOk();

        $this->assertSame('Completed', $completeResponse->json('data.status'));
    }
}

