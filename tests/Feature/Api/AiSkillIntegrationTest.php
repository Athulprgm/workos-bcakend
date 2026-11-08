<?php

namespace Tests\Feature\Api;

use App\Models\AiMemory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiSkillIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manglish_greeting_entha_paripadi_returns_natural_kerala_response(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $user->workspaces()->create([
            'name' => 'Kerala Dev Workspace',
            'slug' => 'kerala-dev-ws',
            'company_type' => 'my_company',
        ]);

        $res = $this->postJson('/api/juju/chat', [
            'message' => 'Entha paripadi?',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $this->assertSame('പ്രത്യേകിച്ച് ഒന്നുമില്ല, പറയൂ എന്താ വിശേഷം?', $res->json('reply'));
        $this->assertSame('പ്രത്യേകിച്ച് ഒന്നുമില്ല, പറയൂ എന്താ വിശേഷം?', $res->json('speak'));
    }

    public function test_code_check_request_returns_natural_code_switching_response(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $user->workspaces()->create([
            'name' => 'Tech Lab',
            'slug' => 'tech-lab',
            'company_type' => 'my_company',
        ]);

        $res = $this->postJson('/api/juju/chat', [
            'message' => 'Can you check my code?',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $this->assertSame('Sure! Code അയക്കൂ, നമുക്ക് പരിശോധിക്കാം.', $res->json('reply'));
        $this->assertSame('Sure! Code അയക്കൂ, നമുക്ക് പരിശോധിക്കാം.', $res->json('speak'));
    }

    public function test_long_term_memory_can_be_stored_retrieved_and_cleared_via_chat(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $user->workspaces()->create([
            'name' => 'Innovation Hub',
            'slug' => 'innovation-hub',
            'company_type' => 'my_company',
        ]);

        // 1. Remember fact
        $storeRes = $this->postJson('/api/juju/chat', [
            'message' => 'Remember that I prefer dark theme and Vim keybindings',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $storeRes->assertJsonPath('action_performed', 'remember_memory');
        $this->assertDatabaseHas('ai_memories', [
            'user_id' => $user->id,
            'content' => 'I prefer dark theme and Vim keybindings',
        ]);

        // 2. Recall memory in English
        $recallRes = $this->postJson('/api/juju/chat', [
            'message' => 'What do you remember about me?',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $recallRes->assertJsonPath('action_performed', 'recall_memories');
        $this->assertStringContainsString('dark theme and Vim keybindings', $recallRes->json('reply'));

        // 3. Clear memories via chat
        $clearRes = $this->postJson('/api/juju/chat', [
            'message' => 'Clear all my memories',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $clearRes->assertJsonPath('action_performed', 'clear_memories');
        $this->assertDatabaseCount('ai_memories', 0);
    }

    public function test_malayalam_memory_storage_and_recall(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $user->workspaces()->create([
            'name' => 'Kochi Office',
            'slug' => 'kochi-office',
            'company_type' => 'my_company',
        ]);

        // 1. Store memory in Malayalam
        $storeRes = $this->postJson('/api/juju/chat', [
            'message' => 'ഇത് ഓർത്തു വെക്കൂ: നാളെ വൈകുന്നേരം ക്ലയന്റ് ഡെമോ ഉണ്ട്',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $storeRes->assertJsonPath('action_performed', 'remember_memory');
        $this->assertDatabaseHas('ai_memories', [
            'user_id' => $user->id,
            'content' => 'നാളെ വൈകുന്നേരം ക്ലയന്റ് ഡെമോ ഉണ്ട്',
        ]);

        // 2. Recall memory in Malayalam
        $recallRes = $this->postJson('/api/juju/chat', [
            'message' => 'എന്നെക്കുറിച്ച് എന്തൊക്കെ ഓർമ്മയുണ്ട്?',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $recallRes->assertJsonPath('action_performed', 'recall_memories');
        $this->assertStringContainsString('നാളെ വൈകുന്നേരം ക്ലയന്റ് ഡെമോ ഉണ്ട്', $recallRes->json('reply'));
    }

    public function test_memory_crud_api_endpoints(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $workspace = $user->workspaces()->create([
            'name' => 'SaaS Project',
            'slug' => 'saas-project',
            'company_type' => 'my_company',
        ]);

        // 1. Create memory via API
        $createRes = $this->postJson('/api/ai/memories', [
            'content' => 'Preferred language is Malayalam with English tech terms',
            'category' => 'preference',
            'key' => 'preferred_language',
            'importance' => 5,
            'workspace_id' => $workspace->id,
        ])->assertStatus(201);

        $memoryId = $createRes->json('memory.id');
        $this->assertNotNull($memoryId);

        // 2. List memories
        $listRes = $this->getJson('/api/ai/memories')->assertOk();
        $this->assertCount(1, $listRes->json('data'));

        // 3. Get summary
        $summaryRes = $this->getJson('/api/ai/memories/summary')->assertOk();
        $this->assertSame(1, $summaryRes->json('total_count'));
        $this->assertStringContainsString('Preferred language is Malayalam', $summaryRes->json('formatted_summary'));

        // 4. Update memory
        $this->patchJson("/api/ai/memories/{$memoryId}", [
            'importance' => 4,
            'content' => 'Updated preferred language instructions',
        ])->assertOk();

        $this->assertDatabaseHas('ai_memories', [
            'id' => $memoryId,
            'content' => 'Updated preferred language instructions',
            'importance' => 4,
        ]);

        // 5. Delete single memory
        $this->deleteJson("/api/ai/memories/{$memoryId}")->assertOk();
        $this->assertDatabaseMissing('ai_memories', ['id' => $memoryId]);
    }
}

