<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\WorkReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkReportPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function reportFor(User $owner, array $attributes = []): WorkReport
    {
        $workspace = $owner->workspaces()->create([
            'name' => 'Acme',
            'slug' => 'acme-'.uniqid(),
        ]);

        return $owner->workReports()->create(array_merge([
            'workspace_id' => $workspace->id,
            'type' => 'daily',
            'period_start' => today()->toDateString(),
            'period_end' => today()->toDateString(),
            'title' => 'Daily report',
            'company_name' => 'Acme',
            'visibility' => 'private',
            'raw_content' => '# Report',
        ], $attributes));
    }

    public function test_private_report_is_only_visible_to_its_owner(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $report = $this->reportFor($owner);

        Sanctum::actingAs($outsider);
        $this->getJson('/api/work-reports/'.$report->id)->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/work-reports/'.$report->id)
            ->assertOk()
            ->assertJsonPath('data.visibility', 'private');
    }

    public function test_public_report_is_readable_but_not_editable_by_another_user(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $report = $this->reportFor($owner, ['visibility' => 'public']);

        Sanctum::actingAs($viewer);
        $this->getJson('/api/work-reports/'.$report->id)->assertOk();
        $this->patchJson('/api/work-reports/'.$report->id, ['title' => 'Hijacked'])->assertForbidden();
        $this->deleteJson('/api/work-reports/'.$report->id)->assertForbidden();
    }

    public function test_owner_can_share_a_report_with_a_general_manager_who_can_read_it(): void
    {
        $owner = User::factory()->create();
        $gm = User::factory()->create(['role' => 'general_manager']);
        $report = $this->reportFor($owner);

        Sanctum::actingAs($owner);
        $this->patchJson('/api/work-reports/'.$report->id, [
            'shared_with_gm' => true,
            'gm_user_id' => $gm->id,
        ])->assertOk()
            ->assertJsonPath('data.shared_with_gm', true)
            ->assertJsonPath('data.gm.id', $gm->id);

        Sanctum::actingAs($gm);
        $this->getJson('/api/work-reports/'.$report->id)
            ->assertOk()
            ->assertJsonPath('data.id', $report->id);

        $this->getJson('/api/work-reports?scope=shared')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/work-reports?scope=mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->patchJson('/api/work-reports/'.$report->id, ['title' => 'GM edit'])->assertForbidden();
    }

    public function test_owner_can_share_by_email_and_unshare(): void
    {
        $owner = User::factory()->create();
        $gm = User::factory()->create(['role' => 'admin']);
        $report = $this->reportFor($owner);

        Sanctum::actingAs($owner);
        $this->patchJson('/api/work-reports/'.$report->id, ['gm_email' => $gm->email])
            ->assertOk()
            ->assertJsonPath('data.gm.id', $gm->id);

        $this->patchJson('/api/work-reports/'.$report->id, ['shared_with_gm' => false])
            ->assertOk()
            ->assertJsonPath('data.shared_with_gm', false);

        $this->assertNull($report->fresh()->gm_user_id);
    }

    public function test_sharing_with_an_unknown_email_is_rejected(): void
    {
        $owner = User::factory()->create();
        $report = $this->reportFor($owner);

        Sanctum::actingAs($owner);
        $this->patchJson('/api/work-reports/'.$report->id, ['gm_email' => 'nobody@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gm_email');
    }

    public function test_existing_private_reports_are_not_exposed_in_public_scope(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $report = $this->reportFor($owner, ['visibility' => 'private']);

        Sanctum::actingAs($viewer);
        $this->getJson('/api/work-reports?scope=public')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($owner);
        $this->getJson('/api/work-reports?scope=public')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame('private', $report->fresh()->visibility->value);
    }
}
