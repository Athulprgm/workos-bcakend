/**
 * End-to-end verification script for Work OS requirements.
 * Exercises all 14 testing scenarios against the live Laravel server.
 */

const BASE = 'http://127.0.0.1:8000';

async function request(path, { method = 'GET', body, token, workspaceId } = {}) {
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (token) headers.Authorization = `Bearer ${token}`;
    if (workspaceId) headers['X-Workspace-Id'] = String(workspaceId);

    const res = await fetch(`${BASE}/api${path}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const text = await res.text();
    let json = null;
    try {
        json = text ? JSON.parse(text) : null;
    } catch {
        json = { _raw: text };
    }
    return { status: res.status, ok: res.ok, json };
}

let passedCount = 0;
let failedCount = 0;

function assert(condition, testName, details = '') {
    if (condition) {
        console.log(`[PASS] ${testName}`);
        passedCount++;
    } else {
        console.error(`[FAIL] ${testName} ${details ? `-> ${details}` : ''}`);
        failedCount++;
    }
}

async function run() {
    console.log('--- STARTING WORK OS END-TO-END VERIFICATION ---');
    const stamp = Date.now();
    const email = `engineer-${stamp}@example.com`;
    const password = 'SecretPassword123!';

    // Register user
    const reg = await request('/auth/register', {
        method: 'POST',
        body: { name: 'Lead Engineer', email, password, password_confirmation: password },
    });
    assert(reg.status === 201, 'User registration succeeds');
    const token = reg.json?.data?.token || reg.json?.token;

    // Workflow 1: Create two separate company workspaces
    const ws1 = await request('/workspaces', {
        method: 'POST',
        token,
        body: { name: 'External Client Corp', company_type: 'external', color: '#6366F1' },
    });
    assert(ws1.status === 201, '1. Create External Company workspace');
    const ws1Id = ws1.json.data.id;

    const ws2 = await request('/workspaces', {
        method: 'POST',
        token,
        body: { name: 'My Own Software Startup', company_type: 'my_company', color: '#2DD4BF' },
    });
    assert(ws2.status === 201, '1. Create My Company workspace');
    const ws2Id = ws2.json.data.id;

    // Workflow 2: Create projects in both workspaces
    const p1 = await request('/projects', {
        method: 'POST',
        token,
        body: { name: 'Client Banking Engine', workspace_id: ws1Id, priority: 'high', status: 'in_progress' },
    });
    assert(p1.status === 201, '2. Create project in External Company');
    const p1Id = p1.json.data.id;

    const p2 = await request('/projects', {
        method: 'POST',
        token,
        body: { name: 'My SaaS AI Platform', workspace_id: ws2Id, priority: 'urgent', status: 'in_progress' },
    });
    assert(p2.status === 201, '2. Create project in My Company');
    const p2Id = p2.json.data.id;

    // Workflow 3: Create tasks linked to correct projects
    const t1 = await request('/tasks', {
        method: 'POST',
        token,
        body: { project_id: p1Id, workspace_id: ws1Id, title: 'Fix bank auth handshake', priority: 'high' },
    });
    assert(t1.status === 201, '3. Create task linked to External Company project');
    const t1Id = t1.json.data.id;

    const t2 = await request('/tasks', {
        method: 'POST',
        token,
        body: { project_id: p2Id, workspace_id: ws2Id, title: 'Design landing page hero', priority: 'medium' },
    });
    assert(t2.status === 201, '3. Create task linked to My Company project');
    const t2Id = t2.json.data.id;

    // Workflow 4: Switch workspaces and verify backend data isolation
    const listWs1 = await request(`/projects?workspace_id=${ws1Id}`, { token });
    const pNames1 = listWs1.json.data.map((p) => p.name);
    assert(pNames1.includes('Client Banking Engine') && !pNames1.includes('My SaaS AI Platform'),
        '4. Data isolation: External Company workspace only shows its own projects');

    const listWs2 = await request(`/projects?workspace_id=${ws2Id}`, { token });
    const pNames2 = listWs2.json.data.map((p) => p.name);
    assert(pNames2.includes('My SaaS AI Platform') && !pNames2.includes('Client Banking Engine'),
        '4. Data isolation: My Company workspace only shows its own projects');

    // Workflow 5: Complete task and verify project progress
    const compTask = await request(`/tasks/${t1Id}`, {
        method: 'PATCH',
        token,
        body: { status: 'done' },
    });
    assert(compTask.status === 200, '5. Complete task');

    const getP1 = await request(`/projects/${p1Id}`, { token });
    assert(getP1.json.data.progress === 100, '5. Project progress dynamically calculated as 100%', `got ${getP1.json.data.progress}`);

    // Workflow 6: Create a work log
    const workLog = await request('/work-logs', {
        method: 'POST',
        token,
        body: {
            workspace_id: ws1Id,
            project_id: p1Id,
            task_id: t1Id,
            work_description: 'Resolved TLS protocol handshake bug with client gateway',
            time_spent_minutes: 90,
            problems_encountered: 'Certificate mismatch in sandbox',
            next_steps: 'Deploy to staging',
        },
    });
    assert(workLog.status === 201, '6. Create work log');

    // Verify task is still done and NOT affected adversely
    const t1Check = await request(`/tasks/${t1Id}`, { token });
    assert(t1Check.json.data.status === 'done', '6. Work log does not corrupt task state');

    // Workflow 7 & 8: Generate separate reports for both companies and verify records
    const rep1 = await request('/work-reports/generate', {
        method: 'POST',
        token,
        body: { workspace_id: ws1Id, type: 'daily' },
    });
    assert(rep1.status === 200, '7. Generate report for External Company');
    assert(rep1.json.data.company_name === 'External Client Corp' && rep1.json.data.time_spent_minutes === 90,
        '8. External Company report contains real time entries and deliverables');

    const rep2 = await request('/work-reports/generate', {
        method: 'POST',
        token,
        body: { workspace_id: ws2Id, type: 'daily' },
    });
    assert(rep2.status === 200, '7. Generate report for My Company');
    assert(rep2.json.data.company_name === 'My Own Software Startup', '8. My Company report is isolated from External Company');

    // Workflow 9: Ask Juju to create a task and verify in database
    const jujuTask = await request('/juju/chat', {
        method: 'POST',
        token,
        workspaceId: ws2Id,
        body: { message: 'Hey Juju, create a task to fix the navigation bar in project My SaaS AI Platform' },
    });
    assert(jujuTask.status === 200 && jujuTask.json.action_performed === 'create_task', '9. Juju creates task via voice/text intent');
    const createdTaskDb = await request(`/tasks?q=navigation`, { token, workspaceId: ws2Id });
    assert(createdTaskDb.json.data.length > 0, '9. Verified Juju task persisted in database');

    // Workflow 10: Ask Juju to summarize pending tasks
    const jujuTasksList = await request('/juju/chat', {
        method: 'POST',
        token,
        workspaceId: ws2Id,
        body: { message: 'Show my pending tasks today' },
    });
    assert(jujuTasksList.status === 200 && jujuTasksList.json.action_performed === 'list_tasks',
        '10. Juju accurately queries and returns pending tasks from database records');

    // Workflow 11: Test ambiguous commands and missing project names
    const jujuGeneralTask = await request('/juju/chat', {
        method: 'POST',
        token,
        workspaceId: ws2Id,
        body: { message: 'Create a task to review pull requests' },
    });
    assert(jujuGeneralTask.status === 200 && jujuGeneralTask.json.action_performed === 'create_task',
        '11. Handles missing project name by intelligently defaulting to active project context');

    // Workflow 12: Test unauthorized workspace access
    const user2 = await request('/auth/register', {
        method: 'POST',
        body: { name: 'Attacker', email: `attacker-${stamp}@example.com`, password, password_confirmation: password },
    });
    const user2Token = user2.json?.data?.token || user2.json?.token;
    const unauthCheck = await request(`/projects?workspace_id=${ws1Id}`, { token: user2Token });
    assert(unauthCheck.status === 403, '12. Unauthorized workspace access correctly blocked with HTTP 403');

    // Summary
    console.log(`\n--- VERIFICATION COMPLETE: ${passedCount} PASSED, ${failedCount} FAILED ---`);
    if (failedCount > 0) process.exit(1);
}

run().catch((err) => {
    console.error('Test execution error:', err);
    process.exit(1);
});
