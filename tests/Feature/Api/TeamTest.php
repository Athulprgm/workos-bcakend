<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function projectFor(User $user, string $name): Project
    {
        return $user->projects()->create([
            'name' => $name,
            'slug' => strtolower($name).'-'.uniqid(),
        ]);
    }

    public function test_only_a_super_admin_can_reach_team_management(): void
    {
        $developer = User::factory()->create(['role' => 'developer']);
        Sanctum::actingAs($developer);

        $this->getJson('/api/team/members')->assertForbidden();
        $this->postJson('/api/team/members', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'role' => 'developer',
            'password' => 'password123',
        ])->assertForbidden();
    }

    public function test_super_admin_can_add_and_list_a_developer(): void
    {
        $admin = $this->superAdmin();

        $response = $this->postJson('/api/team/members', [
            'name' => 'Dev One',
            'email' => 'Dev@Example.com',
            'role' => 'developer',
            'password' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'dev@example.com')
            ->assertJsonPath('data.role', 'developer')
            ->assertJsonPath('data.role_label', 'Developer');

        $this->assertDatabaseHas('users', [
            'email' => 'dev@example.com',
            'role' => 'developer',
            'is_active' => true,
        ]);

        $this->getJson('/api/team/members')
            ->assertOk()
            ->assertJsonFragment(['id' => $admin->id, 'role' => 'super_admin'])
            ->assertJsonFragment(['id' => $response->json('data.id'), 'role' => 'developer']);
    }

    public function test_super_admin_can_create_a_tester_with_a_one_time_access_key(): void
    {
        $admin = $this->superAdmin();
        $project = $this->projectFor($admin, 'Alpha');

        $response = $this->postJson('/api/team/members', [
            'name' => 'Tess Tester',
            'email' => 'tester@example.com',
            'role' => 'tester',
            'project_ids' => [$project->id],
        ])->assertCreated()
            ->assertJsonPath('data.role', 'tester')
            ->assertJsonPath('data.tester_invitation.status', 'active');

        $accessKey = $response->json('access_key');
        $this->assertStringStartsWith('tst_', $accessKey);
        $this->assertDatabaseMissing('tester_invitations', ['key_hash' => $accessKey]);

        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/auth/tester-login', [
            'email' => 'tester@example.com',
            'access_key' => $accessKey,
        ])->assertOk()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/testing/projects')
            ->assertOk()
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_tester_can_be_converted_back_to_a_developer_with_a_password(): void
    {
        $this->superAdmin();

        $testerId = $this->postJson('/api/team/members', [
            'name' => 'Tess Tester',
            'email' => 'tester@example.com',
            'role' => 'tester',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/team/members/{$testerId}", [
            'role' => 'developer',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->patchJson("/api/team/members/{$testerId}", [
            'role' => 'developer',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.role', 'developer');

        $this->assertDatabaseHas('users', ['id' => $testerId, 'role' => 'developer']);
        $this->assertDatabaseHas('tester_invitations', [
            'email' => 'tester@example.com',
            'status' => 'revoked',
        ]);
    }

    public function test_deactivated_members_cannot_sign_in(): void
    {
        $admin = $this->superAdmin();
        $this->assertNotNull($admin);

        $memberId = $this->postJson('/api/team/members', [
            'name' => 'Dev One',
            'email' => 'dev@example.com',
            'role' => 'developer',
            'password' => 'password123',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/team/members/{$memberId}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/login', [
            'email' => 'dev@example.com',
            'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_super_admin_cannot_change_their_own_or_another_super_admin_account(): void
    {
        $admin = $this->superAdmin();
        $other = User::factory()->create(['role' => 'super_admin']);

        $this->patchJson("/api/team/members/{$admin->id}", ['role' => 'developer'])->assertForbidden();
        $this->deleteJson("/api/team/members/{$admin->id}")->assertForbidden();
        $this->patchJson("/api/team/members/{$other->id}", ['role' => 'developer'])->assertForbidden();
    }

    public function test_members_with_data_must_be_deactivated_instead_of_deleted(): void
    {
        $this->superAdmin();

        $member = User::factory()->create(['role' => 'developer']);
        $this->projectFor($member, 'Owned project');

        $this->deleteJson("/api/team/members/{$member->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This member owns projects or work history. Deactivate the account instead of deleting it.');
    }

    public function test_members_without_data_can_be_removed(): void
    {
        $this->superAdmin();

        $member = User::factory()->create(['role' => 'developer']);

        $this->deleteJson("/api/team/members/{$member->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_rotating_a_tester_key_invalidates_the_previous_key(): void
    {
        $this->superAdmin();

        $created = $this->postJson('/api/team/members', [
            'name' => 'Tess Tester',
            'email' => 'tester@example.com',
            'role' => 'tester',
        ])->assertCreated();

        $testerId = $created->json('data.id');
        $oldKey = $created->json('access_key');

        $newKey = $this->postJson("/api/team/members/{$testerId}/tester-key")
            ->assertOk()
            ->json('access_key');

        $this->assertStringStartsWith('tst_', $newKey);
        $this->assertNotSame($oldKey, $newKey);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/auth/tester-login', [
            'email' => 'tester@example.com',
            'access_key' => $oldKey,
        ])->assertStatus(422)->assertJsonValidationErrors('access_key');

        $this->postJson('/api/auth/tester-login', [
            'email' => 'tester@example.com',
            'access_key' => $newKey,
        ])->assertOk();
    }
}
