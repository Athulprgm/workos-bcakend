/**
 * Live end-to-end API check for the WorkOS backend.
 *
 * Exercises the real HTTP API against the running development server and its
 * database. Unlike the PHPUnit suite (which uses an isolated in-memory SQLite
 * database), this proves the whole stack works together.
 *
 * Prerequisites:  php artisan serve --host=127.0.0.1 --port=8123 --no-reload
 * Run:            npm run api-check
 *
 * Override the endpoint with WORKOS_URL if needed.
 */

const BASE = process.env.WORKOS_URL ?? 'http://127.0.0.1:8123';

const results = [];

function check(name, passed, detail = '') {
    results.push({ name, passed, detail });
    console.log(`${passed ? 'PASS' : 'FAIL'}  ${name}${detail ? `  -> ${detail}` : ''}`);
}

async function request(path, { method = 'GET', body, token } = {}) {
    const headers = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(`${BASE}/api${path}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const text = await response.text();
    let json = null;

    try {
        json = text === '' ? null : JSON.parse(text);
    } catch {
        json = { _raw: text.slice(0, 300) };
    }

    return { status: response.status, json, headers: response.headers };
}

const PASSWORD = 'correct-horse-battery';

async function main() {
    const stamp = Date.now();
    const emailA = `api-a-${stamp}@example.com`;
    const emailB = `api-b-${stamp}@example.com`;

    // --- authentication ----------------------------------------------------
    const register = await request('/auth/register', {
        method: 'POST',
        body: { name: 'API Check A', email: emailA, password: PASSWORD, password_confirmation: PASSWORD },
    });
    check('registers a user and returns a token', register.status === 201 && !!register.json?.data?.token,
        `status=${register.status}`);

    const token = register.json.data.token;
    const me = await request('/auth/me', { token });
    check('token authenticates /auth/me', me.status === 200 && me.json?.data?.user?.email === emailA,
        `status=${me.status}`);

    const anonymous = await request('/projects');
    check('unauthenticated access is rejected', anonymous.status === 401, `status=${anonymous.status}`);

    // --- projects ----------------------------------------------------------
    const created = await request('/projects', {
        method: 'POST',
        token,
        body: {
            name: 'WorkOS Platform',
            description: 'Developer operating system',
            tech_stack: ['Laravel', 'React'],
            repository_url: 'https://github.com/example/workos',
        },
    });
    const project = created.json?.data;
    check('creates a project with a generated slug', created.status === 201 && project?.slug === 'workos-platform',
        `status=${created.status} slug=${project?.slug}`);
    check('new project reports zero tasks', project?.tasks_count === 0, `tasks_count=${project?.tasks_count}`);

    const duplicate = await request('/projects', { method: 'POST', token, body: { name: 'WorkOS Platform' } });
    check('duplicate project names get a distinct slug', duplicate.json?.data?.slug === 'workos-platform-2',
        `slug=${duplicate.json?.data?.slug}`);

    const invalidProject = await request('/projects', { method: 'POST', token, body: { name: '' } });
    check('invalid project payload returns 422', invalidProject.status === 422,
        `status=${invalidProject.status}`);

    // --- tasks -------------------------------------------------------------
    const urgent = await request('/tasks', {
        method: 'POST',
        token,
        body: {
            project_id: project.id,
            title: 'Fix login bug',
            priority: 'urgent',
            tags: ['bug', 'Backend'],
        },
    });
    const urgentTask = urgent.json?.data;
    check('creates a task with tags and priority', urgent.status === 201 && urgentTask?.priority === 'urgent',
        `status=${urgent.status} priority=${urgentTask?.priority}`);
    check('task defaults to backlog and is self-assigned', urgentTask?.status === 'backlog' && !!urgentTask?.assignee?.id,
        `status=${urgentTask?.status} assignee=${urgentTask?.assignee?.id}`);

    const blocker = await request('/tasks', {
        method: 'POST',
        token,
        body: { project_id: project.id, title: 'Deploy to staging' },
    });
    const blockerTask = blocker.json?.data;

    // --- dependencies ------------------------------------------------------
    const dependency = await request(`/tasks/${urgentTask.id}/dependencies`, {
        method: 'POST',
        token,
        body: { depends_on_task_id: blockerTask.id },
    });
    check('adding a dependency marks the task blocked',
        dependency.status === 201 && dependency.json?.data?.is_blocked === true,
        `status=${dependency.status} is_blocked=${dependency.json?.data?.is_blocked}`);
    check('blocked_by names the blocking task',
        dependency.json?.data?.blocked_by?.[0]?.title === 'Deploy to staging',
        `blocked_by=${JSON.stringify(dependency.json?.data?.blocked_by?.map((t) => t.title))}`);

    const cycle = await request(`/tasks/${blockerTask.id}/dependencies`, {
        method: 'POST',
        token,
        body: { depends_on_task_id: urgentTask.id },
    });
    check('a circular dependency is rejected', cycle.status === 422, `status=${cycle.status}`);

    const selfDependency = await request(`/tasks/${blockerTask.id}/dependencies`, {
        method: 'POST',
        token,
        body: { depends_on_task_id: blockerTask.id },
    });
    check('self dependency is rejected', selfDependency.status === 422, `status=${selfDependency.status}`);

    // --- filtering and sorting --------------------------------------------
    const byPriority = await request('/tasks?sort=priority', { token });
    check('priority sort puts urgent first', byPriority.json?.data?.[0]?.priority === 'urgent',
        `first=${byPriority.json?.data?.[0]?.priority}`);

    const blockedOnly = await request('/tasks?blocked=1', { token });
    check('blocked filter returns only blocked tasks', blockedOnly.json?.data?.length === 1,
        `count=${blockedOnly.json?.data?.length}`);

    const unblockedOnly = await request('/tasks?blocked=0', { token });
    check('blocked=0 returns only unblocked tasks', unblockedOnly.json?.data?.length === 1,
        `count=${unblockedOnly.json?.data?.length}`);

    const tags = await request('/tags', { token });
    check('tags endpoint lists the created tags', tags.json?.data?.length === 2,
        `count=${tags.json?.data?.length}`);

    // --- completing the blocker unblocks the dependent task ---------------
    await request(`/tasks/${blockerTask.id}`, { method: 'PATCH', token, body: { status: 'done' } });

    const afterDone = await request(`/tasks/${urgentTask.id}`, { token });
    check('finishing the dependency unblocks the task', afterDone.json?.data?.is_blocked === false,
        `is_blocked=${afterDone.json?.data?.is_blocked}`);

    const blockedNow = await request('/tasks?blocked=1', { token });
    check('nothing is blocked once the dependency is done', blockedNow.json?.data?.length === 0,
        `count=${blockedNow.json?.data?.length}`);

    const completedAt = afterDone.json?.data?.completed_at;
    const doneTask = await request(`/tasks/${blockerTask.id}`, { token });
    check('completed_at is recorded when a task is finished', doneTask.json?.data?.completed_at !== null,
        `completed_at=${doneTask.json?.data?.completed_at}`);
    check('the blocked task is not marked completed', completedAt === null, `completed_at=${completedAt}`);

    // --- validation guards -------------------------------------------------
    const moveProject = await request(`/tasks/${urgentTask.id}`, {
        method: 'PATCH', token, body: { project_id: project.id },
    });
    check('moving a task between projects is rejected', moveProject.status === 422,
        `status=${moveProject.status}`);

    // Look the project up by id: the index is newest-first by default, so
    // data[0] is the duplicate project created earlier, not this one.
    const countAfter = await request('/projects', { token });
    const countedProject = countAfter.json?.data?.find((p) => p.id === project.id);
    check('project task count reflects its tasks', countedProject?.tasks_count === 2,
        `tasks_count=${countedProject?.tasks_count}`);

    // --- cross-user isolation ---------------------------------------------
    const registerB = await request('/auth/register', {
        method: 'POST',
        body: { name: 'API Check B', email: emailB, password: PASSWORD, password_confirmation: PASSWORD },
    });
    const tokenB = registerB.json.data.token;

    const projectsB = await request('/projects', { token: tokenB });
    check("a second user sees none of the first user's projects", projectsB.json?.meta?.total === 0,
        `total=${projectsB.json?.meta?.total}`);

    const readAsB = await request(`/tasks/${urgentTask.id}`, { token: tokenB });
    check("a second user cannot read the first user's task", readAsB.status === 403, `status=${readAsB.status}`);

    const writeAsB = await request(`/tasks/${urgentTask.id}`, {
        method: 'PATCH', token: tokenB, body: { title: 'Hijacked' },
    });
    check("a second user cannot modify the first user's task", writeAsB.status === 403, `status=${writeAsB.status}`);

    // --- deletion cascades -------------------------------------------------
    const deleted = await request(`/projects/${project.id}`, { method: 'DELETE', token });
    check('deleting a project returns 204', deleted.status === 204, `status=${deleted.status}`);

    const tasksAfter = await request('/tasks', { token });
    check('deleting a project removes its tasks', tasksAfter.json?.meta?.total === 0,
        `total=${tasksAfter.json?.meta?.total}`);

    const failed = results.filter((r) => !r.passed);
    console.log(`\n${results.length - failed.length}/${results.length} API checks passed`);

    if (failed.length > 0) {
        console.log('\nFailures:');
        failed.forEach((f) => console.log(`  - ${f.name} (${f.detail})`));
    }

    process.exit(failed.length === 0 ? 0 : 1);
}

main().catch((error) => {
    console.error(`\nAPI CHECK CRASHED: ${error.message}`);
    process.exit(2);
});
