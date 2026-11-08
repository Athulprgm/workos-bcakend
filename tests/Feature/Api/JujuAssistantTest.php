<?php

namespace Tests\Feature\Api;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JujuAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_juju_can_create_and_complete_tasks_via_voice_command(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'My Software Lab',
            'slug' => 'my-software-lab',
            'company_type' => 'my_company',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Web App',
            'slug' => 'web-app',
        ]);

        // 1. Create task command
        $createRes = $this->postJson('/api/juju/chat', [
            'message' => 'Hey Juju, create a task to fix the login page in project Web App',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $createRes->assertJsonPath('action_performed', 'create_task');
        $this->assertDatabaseHas('tasks', [
            'title' => 'fix the login page',
            'project_id' => $project->id,
        ]);

        $task = Task::where('title', 'fix the login page')->firstOrFail();

        // 2. Complete task command
        $completeRes = $this->postJson('/api/juju/chat', [
            'message' => 'Mark the login task as completed',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $completeRes->assertJsonPath('action_performed', 'complete_task');
        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
    }

    public function test_juju_can_create_project_and_record_work_log(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'External Consulting',
            'slug' => 'external-consulting',
            'company_type' => 'external',
        ]);

        // Create project
        $projRes = $this->postJson('/api/juju/chat', [
            'message' => 'Create a project called E-commerce Platform',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $projRes->assertJsonPath('action_performed', 'create_project');
        $this->assertDatabaseHas('projects', [
            'name' => 'E-commerce Platform',
            'workspace_id' => $workspace->id,
        ]);

        // Record work log
        $logRes = $this->postJson('/api/juju/chat', [
            'message' => 'Record that I fixed the commission calculation today',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $logRes->assertJsonPath('action_performed', 'create_work_log');
        $this->assertDatabaseHas('work_logs', [
            'workspace_id' => $workspace->id,
            'work_description' => 'I fixed the commission calculation today',
        ]);
    }

    public function test_juju_can_switch_workspace_and_summarize_workload(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $user->ensureDefaultWorkspaces();

        // Switch to own company
        $switchRes = $this->postJson('/api/juju/chat', [
            'message' => 'Switch to My Company',
        ])->assertOk();

        $switchRes->assertJsonPath('action_performed', 'switch_workspace');
        $this->assertSame('My Company', $switchRes->json('data.workspace_name'));

        // Show workload across all companies
        $workloadRes = $this->postJson('/api/juju/chat', [
            'message' => 'Show my workload across all companies',
        ])->assertOk();

        $workloadRes->assertJsonPath('action_performed', 'get_workload_summary');
    }

    public function test_juju_responds_in_natural_malayalam_for_malayalam_queries(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Tech Solutions',
            'slug' => 'tech-solutions',
            'company_type' => 'my_company',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Mobile App',
            'slug' => 'mobile-app',
        ]);

        $user->tasks()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Fix Login API bug',
            'priority' => 'high',
            'status' => 'todo',
        ]);

        // 1. Malayalam Greeting
        $greetingRes = $this->postJson('/api/juju/chat', [
            'message' => 'നമസ്കാരം, സുഖമാണോ?',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $reply = $greetingRes->json('reply');
        $speak = $greetingRes->json('speak');

        $this->assertStringContainsString('നമസ്കാരം', $reply);
        $this->assertSame(1, preg_match('/[\x{0D00}-\x{0D7F}]/u', $speak), 'Speech output should contain valid Malayalam characters');

        // 2. Malayalam Workload / Task query
        $taskRes = $this->postJson('/api/juju/chat', [
            'message' => 'എന്റെ ടാസ്ക്കുകൾ എന്തൊക്കെയാണ്?',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $taskReply = $taskRes->json('reply');
        $taskSpeak = $taskRes->json('speak');

        $this->assertNotEmpty($taskReply);
        $this->assertNotEmpty($taskSpeak);
        // Verify no raw markdown asterisks or truncated broken UTF-8 in speech text
        $this->assertStringNotContainsString('**', $taskSpeak);
        $this->assertTrue(mb_check_encoding($taskSpeak, 'UTF-8'));
    }

    public function test_juju_speech_sanitization_preserves_utf8_and_sentence_integrity(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Lab Inc',
            'slug' => 'lab-inc',
            'company_type' => 'external',
        ]);

        $res = $this->postJson('/api/juju/chat', [
            'message' => 'Give me a summary of priorities',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $speak = $res->json('speak');
        $this->assertNotEmpty($speak);
        $this->assertStringNotContainsString('###', $speak);
        $this->assertStringNotContainsString('•', $speak);
        $this->assertTrue(mb_check_encoding($speak, 'UTF-8'));
    }

    public function test_juju_asks_for_missing_task_title_and_completes_in_follow_up(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Design Studio',
            'slug' => 'design-studio',
            'company_type' => 'my_company',
        ]);

        // Step 1: User says "Create a task for tomorrow" without title
        $initialRes = $this->postJson('/api/juju/chat', [
            'message' => 'Create a task for tomorrow',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $initialRes->assertJsonPath('action_performed', 'ask_followup');
        $this->assertSame('What task would you like me to create?', $initialRes->json('reply'));
        $pendingAction = $initialRes->json('context_update.pending_action');
        $this->assertNotNull($pendingAction);
        $this->assertSame('title', $pendingAction['missing_field']);

        // Step 2: User answers follow-up with the title "Finish the dashboard"
        $followUpRes = $this->postJson('/api/juju/chat', [
            'message' => 'Finish the dashboard',
            'workspace_id' => $workspace->id,
            'context' => [
                'pending_action' => $pendingAction,
            ],
        ])->assertOk();

        $followUpRes->assertJsonPath('action_performed', 'create_task');
        $this->assertDatabaseHas('tasks', [
            'title' => 'Finish the dashboard',
            'workspace_id' => $workspace->id,
        ]);

        $createdTask = Task::where('title', 'Finish the dashboard')->firstOrFail();
        $this->assertNotNull($createdTask->due_at);
        $this->assertTrue($createdTask->due_at->isTomorrow());
    }

    public function test_juju_creates_high_priority_task_with_due_date_directly(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Engineering Hub',
            'slug' => 'engineering-hub',
            'company_type' => 'my_company',
        ]);

        $res = $this->postJson('/api/juju/chat', [
            'message' => 'Create a high-priority task to complete the dashboard tomorrow',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $res->assertJsonPath('action_performed', 'create_task');
        $this->assertStringContainsString('created your high-priority dashboard task with a due date of tomorrow', $res->json('speak'));

        $this->assertDatabaseHas('tasks', [
            'title' => 'complete the dashboard',
            'priority' => 'high',
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_juju_multi_turn_task_query_and_completion_using_context(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Dev Ops',
            'slug' => 'dev-ops',
            'company_type' => 'my_company',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Infrastructure',
            'slug' => 'infra',
        ]);

        $taskUrgent = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Fix SSL Certificate',
            'priority' => 'urgent',
            'status' => TaskStatus::Todo->value,
        ]);

        $taskLow = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Update documentation',
            'priority' => 'low',
            'status' => TaskStatus::Todo->value,
        ]);

        // 1. Show pending tasks
        $listRes = $this->postJson('/api/juju/chat', [
            'message' => 'Show my pending tasks',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $listRes->assertJsonPath('action_performed', 'list_tasks');
        $contextUpdate = $listRes->json('context_update');
        $this->assertNotEmpty($contextUpdate['last_tasks']);

        // 2. Ask which one has the highest priority
        $priorityRes = $this->postJson('/api/juju/chat', [
            'message' => 'Which one has the highest priority?',
            'workspace_id' => $workspace->id,
            'context' => $contextUpdate,
        ])->assertOk();

        $priorityRes->assertJsonPath('action_performed', 'identify_highest_priority_task');
        $this->assertStringContainsString('Fix SSL Certificate', $priorityRes->json('reply'));
        $contextPriority = $priorityRes->json('context_update');
        $this->assertSame($taskUrgent->id, $contextPriority['last_referenced_task_id']);

        // 3. Mark it as completed
        $completeRes = $this->postJson('/api/juju/chat', [
            'message' => 'Mark it as completed',
            'workspace_id' => $workspace->id,
            'context' => $contextPriority,
        ])->assertOk();

        $completeRes->assertJsonPath('action_performed', 'complete_task');
        $taskUrgent->refresh();
        $this->assertSame(TaskStatus::Done, $taskUrgent->status);
    }

    public function test_juju_suggests_next_task_prioritizing_overdue(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Agency',
            'slug' => 'agency',
            'company_type' => 'external',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Client Portal',
            'slug' => 'client-portal',
        ]);

        $overdueTask = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Resolve Payment Webhook',
            'priority' => 'high',
            'status' => TaskStatus::Todo->value,
            'due_at' => today()->subDays(2),
        ]);

        $res = $this->postJson('/api/juju/chat', [
            'message' => 'What should I work on next?',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $res->assertJsonPath('action_performed', 'suggest_next_task');
        $this->assertStringContainsString('Resolve Payment Webhook', $res->json('reply'));
    }

    public function test_juju_destructive_action_requires_confirmation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Main',
            'slug' => 'main',
            'company_type' => 'my_company',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Old Prototype',
            'slug' => 'old-prototype',
        ]);

        // Attempt deletion without confirmation
        $res = $this->postJson('/api/juju/chat', [
            'message' => 'Delete project Old Prototype',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $res->assertJsonPath('requires_confirmation', true);
        $res->assertJsonPath('action_performed', 'confirm_destructive_action');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);

        // Now execute confirmed action
        $confirmRes = $this->postJson('/api/juju/chat', [
            'message' => 'Confirmed',
            'workspace_id' => $workspace->id,
            'confirmed_action' => [
                'action' => 'delete_project',
                'project_id' => $project->id,
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_juju_wake_word_detection_returns_natural_greeting(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/juju/chat', [
            'message' => 'Hey Juju',
        ])->assertOk();

        $res->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertSame('Eda mone! Yes, how can I help you?', $res->json('speak'));

        // Test Wake up
        $resWake = $this->postJson('/api/juju/chat', [
            'message' => 'wake up',
        ])->assertOk();
        $resWake->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('Eda mone', $resWake->json('reply'));

        // Test "hey juju and wakeup"
        $resHeyAndWake = $this->postJson('/api/juju/chat', [
            'message' => 'hey juju and wakeup',
        ])->assertOk();
        $resHeyAndWake->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('Eda mone', $resHeyAndWake->json('reply'));

        // Test Eda mone friend wake
        $resEdaMone = $this->postJson('/api/juju/chat', [
            'message' => 'eda mone',
        ])->assertOk();
        $resEdaMone->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('Eda mone', $resEdaMone->json('reply'));

        // Test Wakeup Call
        $resCall = $this->postJson('/api/juju/chat', [
            'message' => 'wakeup call',
        ])->assertOk();
        $resCall->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('wake-up call', $resCall->json('reply'));

        // Test hyphenated "wake-up call"
        $resHyphenCall = $this->postJson('/api/juju/chat', [
            'message' => 'wake-up call',
        ])->assertOk();
        $resHyphenCall->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('wake-up call', $resHyphenCall->json('reply'));

        // Test "give me a wake up call"
        $resGiveCall = $this->postJson('/api/juju/chat', [
            'message' => 'give me a wake up call',
        ])->assertOk();
        $resGiveCall->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('wake-up call', $resGiveCall->json('reply'));

        // Test "give me a wake-up call"
        $resGiveHyphenCall = $this->postJson('/api/juju/chat', [
            'message' => 'give me a wake-up call',
        ])->assertOk();
        $resGiveHyphenCall->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('wake-up call', $resGiveHyphenCall->json('reply'));

        // Test "wake up call" with Siri persona active
        $resSiriCall = $this->postJson('/api/juju/chat', [
            'message' => 'wake up call',
            'context' => ['persona' => 'siri'],
        ])->assertOk();
        $resSiriCall->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('wake-up call', $resSiriCall->json('reply'));

        // Test "hey juju wakeup call"
        $resHeyCall = $this->postJson('/api/juju/chat', [
            'message' => 'hey juju wakeup call',
        ])->assertOk();
        $resHeyCall->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('wake-up call', $resHeyCall->json('reply'));

        // Test Malayalam wake up call
        $resMlWakeCall = $this->postJson('/api/juju/chat', [
            'message' => 'വേക്കപ്പ് കോൾ',
        ])->assertOk();
        $resMlWakeCall->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('wake-up call', $resMlWakeCall->json('reply'));

        // Test Malayalam wake word
        $resMl = $this->postJson('/api/juju/chat', [
            'message' => 'ഹേ ജൂജു',
        ])->assertOk();

        $resMl->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertSame('എടാ മോനേ! പറയൂ, ഞാൻ എങ്ങനെ സഹായിക്കണം?', $resMl->json('speak'));

        // Test "hey google"
        $resHeyGoogle = $this->postJson('/api/juju/chat', [
            'message' => 'Hey Google',
        ])->assertOk();
        $resHeyGoogle->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString('how can I help', $resHeyGoogle->json('reply'));

        // Test "hey siri"
        $resHeySiri = $this->postJson('/api/juju/chat', [
            'message' => 'Hey Siri',
        ])->assertOk();
        $resHeySiri->assertJsonPath('action_performed', 'wake_word_detected');
        $this->assertStringContainsString("I'm listening", $resHeySiri->json('reply'));

        // Test Malayalam unaroo
        $resUnaroo = $this->postJson('/api/juju/chat', [
            'message' => 'ഉണരൂ',
        ])->assertOk();
        $resUnaroo->assertJsonPath('action_performed', 'wake_word_detected');
    }

    public function test_juju_can_update_task_due_date_and_delete_task_with_confirmation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Operations',
            'slug' => 'operations',
            'company_type' => 'my_company',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Backend Core',
            'slug' => 'backend-core',
        ]);

        $task = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Renew SSL Certificate',
            'priority' => 'medium',
            'status' => TaskStatus::Todo->value,
        ]);

        // 1. Update due date
        $dueRes = $this->postJson('/api/juju/chat', [
            'message' => 'Set due date of task Renew SSL Certificate to tomorrow',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $dueRes->assertJsonPath('action_performed', 'update_task_due_date');
        $task->refresh();
        $this->assertNotNull($task->due_at);
        $this->assertTrue($task->due_at->isTomorrow());

        // 2. Request delete task (requires confirmation)
        $deleteReq = $this->postJson('/api/juju/chat', [
            'message' => 'Delete task Renew SSL Certificate',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $deleteReq->assertJsonPath('requires_confirmation', true);
        $deleteReq->assertJsonPath('action_performed', 'confirm_destructive_action');

        // 3. Confirm delete task
        $confirmDelete = $this->postJson('/api/juju/chat', [
            'message' => 'Yes, delete it',
            'workspace_id' => $workspace->id,
            'confirmed_action' => [
                'action' => 'delete_task',
                'task_id' => $task->id,
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_juju_can_switch_assistant_personas(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Switch to Siri
        $siriRes = $this->postJson('/api/juju/chat', [
            'message' => 'reply like siri',
        ])->assertOk();

        $siriRes->assertJsonPath('action_performed', 'persona_switched');
        $siriRes->assertJsonPath('persona', 'siri');
        $this->assertStringContainsString('Siri', $siriRes->json('speak'));

        // Wake word with Siri persona in context
        $siriWake = $this->postJson('/api/juju/chat', [
            'message' => 'Hey Juju',
            'context' => ['persona' => 'siri'],
        ])->assertOk();

        $this->assertSame("Mm-hmm, I'm listening.", $siriWake->json('speak'));

        // Switch to Google
        $googleRes = $this->postJson('/api/juju/chat', [
            'message' => 'switch to google',
        ])->assertOk();

        $googleRes->assertJsonPath('action_performed', 'persona_switched');
        $googleRes->assertJsonPath('persona', 'google');
        $this->assertStringContainsString('Google', $googleRes->json('speak'));

        // Switch to Alexa
        $alexaRes = $this->postJson('/api/juju/chat', [
            'message' => 'reply like alexa',
        ])->assertOk();

        $alexaRes->assertJsonPath('action_performed', 'persona_switched');
        $alexaRes->assertJsonPath('persona', 'alexa');
    }

    public function test_juju_can_generate_work_report(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Report Workspace',
            'slug' => 'report-workspace',
            'company_type' => 'my_company',
        ]);

        $res = $this->postJson('/api/juju/chat', [
            'message' => 'generate today\'s report',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $res->assertJsonPath('action_performed', 'generate_report');
        $this->assertNotNull($res->json('data.raw_content'));
    }

    public function test_juju_can_create_and_complete_tasks_in_malayalam(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Kerala Tech Hub',
            'slug' => 'kerala-tech-hub',
            'company_type' => 'my_company',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Main App',
            'slug' => 'main-app',
        ]);

        // 1. Create high priority task in Malayalam for tomorrow
        $createRes = $this->postJson('/api/juju/chat', [
            'message' => 'ഒരു ഹൈ പ്രയോറിറ്റി ടാസ്ക് ആഡ് ചെയ്യൂ: Client Meeting നാളെ',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $createRes->assertJsonPath('action_performed', 'create_task');
        $this->assertDatabaseHas('tasks', [
            'title' => 'Client Meeting',
            'priority' => 'high',
        ]);

        $task = Task::where('title', 'Client Meeting')->firstOrFail();
        $this->assertNotNull($task->due_at);
        $this->assertTrue($task->due_at->isTomorrow());
        $this->assertStringContainsString('Client Meeting', $createRes->json('speak'));

        // 2. Complete task in Malayalam
        $completeRes = $this->postJson('/api/juju/chat', [
            'message' => 'Client Meeting ടാസ്ക് കംപ്ലീറ്റ് ആക്കൂ',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $completeRes->assertJsonPath('action_performed', 'complete_task');
        $task->refresh();
        $this->assertSame(TaskStatus::Done, $task->status);
        $this->assertStringContainsString('പൂർത്തിയായി', $completeRes->json('reply'));
    }

    public function test_juju_can_record_work_log_and_list_tasks_in_malayalam(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $workspace = $user->workspaces()->create([
            'name' => 'Dev Studio',
            'slug' => 'dev-studio',
            'company_type' => 'my_company',
        ]);

        $project = $user->projects()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Backend API',
            'slug' => 'backend-api',
        ]);

        $task = $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'title' => 'Fix Docker Compose setup',
            'status' => TaskStatus::Todo->value,
            'priority' => 'urgent',
        ]);

        // 1. Record work log in Malayalam with hours
        $logRes = $this->postJson('/api/juju/chat', [
            'message' => 'വർക്ക് ലോഗ് ചെയ്യ്: 2 മണിക്കൂർ Fixed Docker network bug',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $logRes->assertJsonPath('action_performed', 'create_work_log');
        $this->assertDatabaseHas('work_logs', [
            'workspace_id' => $workspace->id,
            'work_description' => 'Fixed Docker network bug',
            'time_spent_minutes' => 120,
        ]);

        // 2. List pending tasks in Malayalam
        $listRes = $this->postJson('/api/juju/chat', [
            'message' => 'പെൻഡിംഗ് ടാസ്ക്കുകൾ കാണിക്കൂ',
            'workspace_id' => $workspace->id,
        ])->assertOk();

        $listRes->assertJsonPath('action_performed', 'list_tasks');
        $this->assertCount(1, $listRes->json('data'));
        $this->assertStringContainsString('Fix Docker Compose setup', $listRes->json('reply'));
    }

    public function test_juju_natural_friend_greetings_sound_authentic_without_tto(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res1 = $this->postJson('/api/juju/chat', [
            'message' => 'എന്തൊക്കെയുണ്ട് വിശേഷങ്ങൾ',
        ])->assertOk();

        $res1->assertJsonPath('action_performed', 'greeting');
        $this->assertStringNotContainsString('ട്ടോ', $res1->json('reply'));
        $this->assertStringNotContainsString('ട്ടോ', $res1->json('speak'));

        $res2 = $this->postJson('/api/juju/chat', [
            'message' => 'സുഖമാണോ ബ്രോ',
        ])->assertOk();

        $res2->assertJsonPath('action_performed', 'greeting');
        $this->assertStringNotContainsString('ട്ടോ', $res2->json('reply'));
        $this->assertStringNotContainsString('ട്ടോ', $res2->json('speak'));
    }
}
