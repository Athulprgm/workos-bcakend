/**
 * Browser-driven end-to-end check of the WorkOS login flow.
 *
 * Drives headless Chrome over the DevTools Protocol (no extra dependencies:
 * Node 22 provides a global WebSocket and fetch).
 *
 * Prerequisites:
 *   1. php artisan serve --host=127.0.0.1 --port=8123 --no-reload
 *   2. Chrome started with --remote-debugging-port=9222
 *
 * Run:  npm run ui-check
 *
 * Override the endpoints with WORKOS_URL and WORKOS_CDP_URL if needed.
 */

const CDP = process.env.WORKOS_CDP_URL ?? 'http://127.0.0.1:9222';
const BASE = process.env.WORKOS_URL ?? 'http://127.0.0.1:8123';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const results = [];
function check(name, passed, detail = '') {
    results.push({ name, passed, detail });
    console.log(`${passed ? 'PASS' : 'FAIL'}  ${name}${detail ? `  -> ${detail}` : ''}`);
}

class Cdp {
    constructor(ws) {
        this.ws = ws;
        this.id = 0;
        this.pending = new Map();
        ws.onmessage = (event) => {
            const msg = JSON.parse(event.data);
            if (msg.id && this.pending.has(msg.id)) {
                const { resolve, reject } = this.pending.get(msg.id);
                this.pending.delete(msg.id);
                if (msg.error) reject(new Error(JSON.stringify(msg.error)));
                else resolve(msg.result);
            }
        };
    }

    static async connect(wsUrl) {
        const ws = new WebSocket(wsUrl);
        await new Promise((resolve, reject) => {
            ws.onopen = resolve;
            ws.onerror = () => reject(new Error('CDP websocket failed to open'));
        });
        return new Cdp(ws);
    }

    send(method, params = {}) {
        const id = ++this.id;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject });
            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    async eval(expression) {
        const out = await this.send('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });
        if (out.exceptionDetails) {
            throw new Error(`eval threw: ${out.exceptionDetails.exception?.description ?? 'unknown'}`);
        }
        return out.result.value;
    }

    /** Poll an expression until it is truthy or the timeout elapses. */
    async waitFor(expression, { timeout = 10000, label = expression } = {}) {
        const deadline = Date.now() + timeout;
        while (Date.now() < deadline) {
            if (await this.eval(expression)) return true;
            await sleep(120);
        }
        throw new Error(`timed out waiting for: ${label}`);
    }

    close() {
        this.ws.close();
    }
}

async function main() {
    // --- connect to Chrome -------------------------------------------------
    let version = null;
    for (let i = 0; i < 60; i++) {
        try {
            const res = await fetch(`${CDP}/json/version`);
            version = await res.json();
            break;
        } catch {
            await sleep(250);
        }
    }
    if (!version) throw new Error(`Chrome DevTools endpoint not reachable at ${CDP}`);
    console.log(`Chrome: ${version.Browser}\n`);

    // --- open a fresh tab --------------------------------------------------
    const created = await fetch(`${CDP}/json/new?about:blank`, { method: 'PUT' });
    const target = await created.json();
    const cdp = await Cdp.connect(target.webSocketDebuggerUrl);

    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');

    // Capture page errors so a runtime crash cannot pass as a blank page.
    const pageErrors = [];
    cdp.ws.addEventListener('message', (event) => {
        const msg = JSON.parse(event.data);
        if (msg.method === 'Runtime.exceptionThrown') {
            pageErrors.push(msg.params.exceptionDetails.exception?.description ?? 'unknown exception');
        }
    });

    // Helper injected into the page. React ignores direct value assignment, so
    // the native setter plus a bubbling input event is required to update state.
    const helpers = `
        window.__setField = (selector, value) => {
            const el = document.querySelector(selector);
            if (!el) throw new Error('no element for ' + selector);
            const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, value);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        };
        window.__clickText = (text) => {
            const el = [...document.querySelectorAll('button, a')].find((n) => n.textContent.trim() === text);
            if (!el) throw new Error('no clickable with text ' + text);
            el.click();
        };
        true;
    `;

    const email = `ui${Date.now()}@example.com`;
    const password = 'correct-horse-battery';

    // Helpers live on the page and are wiped by a real page load, so they have
    // to be reinstalled after every full navigation.
    const installHelpers = () => cdp.eval(helpers);

    try {
        // --- 0. start from a guaranteed clean guest state ------------------
        // localStorage lives in the Chrome profile and survives between runs,
        // so a leftover token would make this script non-idempotent.
        await cdp.send('Page.navigate', { url: `${BASE}/` });
        await cdp.waitFor(`document.readyState === 'complete'`, { label: 'initial page load' });
        await cdp.eval(`window.localStorage.clear()`);

        // --- 1. anonymous visitors land on the login screen ----------------
        await cdp.send('Page.navigate', { url: `${BASE}/overview` });
        await cdp.waitFor(`document.querySelector('form') !== null`, { label: 'login form on /overview' });

        check(
            'protected route redirects anonymous user to /login',
            await cdp.eval(`location.pathname === '/login'`),
            `pathname=${await cdp.eval('location.pathname')}`,
        );

        await installHelpers();

        // --- 2. client-side validation errors are surfaced -----------------
        await cdp.eval(`window.__clickText('Create one')`);
        await cdp.waitFor(`document.querySelector('#name') !== null`, { label: 'register form' });
        await cdp.eval(`document.querySelector('button[type=submit]').click()`);
        await cdp.waitFor(`document.querySelector('#name-error') !== null`, { label: 'validation error for name' });

        check(
            'empty registration shows inline field errors',
            await cdp.eval(`document.querySelectorAll('[id$="-error"]').length >= 3`),
            `${await cdp.eval(`document.querySelectorAll('[id$="-error"]').length`)} errors shown`,
        );

        // --- 3. successful registration logs the user in -------------------
        await cdp.eval(`window.__setField('#name', 'UI Check')`);
        await cdp.eval(`window.__setField('#email', '${email}')`);
        await cdp.eval(`window.__setField('#password', '${password}')`);
        await cdp.eval(`window.__setField('#password_confirmation', '${password}')`);
        await cdp.eval(`document.querySelector('button[type=submit]').click()`);

        await cdp.waitFor(`location.pathname === '/'`, { label: 'redirect to overview after register', timeout: 15000 });

        check(
            'registration redirects to the dashboard',
            await cdp.eval(`location.pathname === '/'`),
            `pathname=${await cdp.eval('location.pathname')}`,
        );

        await cdp.waitFor(`document.body.innerText.includes('${email}')`, { label: 'user email in shell' });

        check(
            'authenticated shell shows the real user email',
            await cdp.eval(`document.body.innerText.includes('${email}')`),
        );

        // The aggregate figures come from /api/dashboard, so wait for them
        // before interacting with anything else on the page.
        // The stat labels are rendered with `text-transform: uppercase`, and
        // innerText reflects the rendered text, so compare case-insensitively.
        const bodyText = `document.body.innerText.toLowerCase()`;

        await cdp.waitFor(`${bodyText}.includes('active projects')`, { label: 'dashboard aggregates' });

        check(
            'dashboard renders real aggregate stats',
            await cdp.eval(`${bodyText}.includes('open tasks') && ${bodyText}.includes('overdue')`),
        );

        // The build status board is collapsed by default.
        await cdp.eval(`window.__clickText('Build status')`);
        await cdp.waitFor(`document.body.innerText.includes('Not built')`, { label: 'build status board' });

        check(
            'dashboard exposes the module build status board',
            await cdp.eval(`document.body.innerText.includes('Not built')`),
        );

        const token = await cdp.eval(`window.localStorage.getItem('workos.token')`);
        check('auth token persisted to localStorage', typeof token === 'string' && token.length > 20,
            `token length=${token ? token.length : 0}`);

        // --- 4. session survives a full page reload ------------------------
        await cdp.send('Page.navigate', { url: `${BASE}/` });
        await cdp.waitFor(`document.body.innerText.includes('${email}')`, { label: 'session restored after reload', timeout: 15000 });

        check('session is restored after a full page reload', true);

        // --- 5. sign out ---------------------------------------------------
        await installHelpers();
        await cdp.eval(`window.__clickText('Sign out')`);
        await cdp.waitFor(`location.pathname === '/login'`, { label: 'redirect to login after sign out' });

        check(
            'sign out clears the session and returns to /login',
            (await cdp.eval(`window.localStorage.getItem('workos.token')`)) === null,
            'token removed from localStorage',
        );

        // --- 6. rejected login shows an error, does not navigate -----------
        await installHelpers();
        await cdp.eval(`document.querySelector('#email') && window.__setField('#email', '${email}')`);
        await cdp.eval(`window.__setField('#password', 'definitely-the-wrong-password')`);
        await cdp.eval(`document.querySelector('button[type=submit]').click()`);
        await cdp.waitFor(`document.querySelector('[role=alert]') !== null`, { label: 'login error alert' });

        check(
            'invalid credentials show an error and stay on /login',
            (await cdp.eval(`location.pathname`)) === '/login' &&
                (await cdp.eval(`document.querySelector('[role=alert]').innerText`)).length > 0,
            await cdp.eval(`document.querySelector('[role=alert]').innerText`),
        );

        // --- 7. valid login works ------------------------------------------
        await cdp.eval(`window.__setField('#password', '${password}')`);
        await cdp.eval(`document.querySelector('button[type=submit]').click()`);
        await cdp.waitFor(`location.pathname === '/'`, { label: 'redirect after successful login', timeout: 15000 });

        check('valid credentials sign the user in', await cdp.eval(`document.body.innerText.includes('${email}')`));

        // --- 8. projects module --------------------------------------------
        await cdp.send('Page.navigate', { url: `${BASE}/projects` });
        await cdp.waitFor(`document.body.innerText.includes('New project')`, { label: 'projects page' });
        await installHelpers();

        check(
            'projects page renders for an authenticated user',
            await cdp.eval(`document.body.innerText.includes('Your projects')`),
        );

        await cdp.eval(`window.__setField('#project-name', 'UI Project')`);
        await cdp.eval(`window.__setField('#project-tech', 'Laravel, React')`);
        await cdp.eval(`window.__clickText('Create project')`);
        await cdp.waitFor(
            `document.body.innerText.includes('UI Project') && document.body.innerText.includes('0 tasks')`,
            { label: 'created project in the list' },
        );

        check('creating a project through the UI persists it',
            await cdp.eval(`document.body.innerText.includes('UI Project')`));

        // --- 9. tasks module ------------------------------------------------
        // A full navigation proves the server serves the SPA shell on deep links.
        await cdp.send('Page.navigate', { url: `${BASE}/tasks` });
        // Waiting on the form itself avoids racing the project list fetch.
        await cdp.waitFor(`document.querySelector('#task-project') !== null`, { label: 'task form (projects loaded)' });
        await installHelpers();

        const selected = await cdp.eval(`
            (() => {
                const select = document.querySelector('#task-project');
                if (!select) return 'no project select';
                const option = [...select.options].find((o) => o.value !== '');
                if (!option) return 'no project option';
                const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
                setter.call(select, option.value);
                select.dispatchEvent(new Event('change', { bubbles: true }));
                return 'ok';
            })()
        `);
        check('task form is populated with the project created moments ago', selected === 'ok', selected);

        await cdp.eval(`window.__setField('#task-title', 'UI Task')`);
        await cdp.eval(`window.__clickText('Create task')`);
        await cdp.waitFor(`document.body.innerText.includes('UI Task')`, { label: 'created task in the list' });

        check('creating a task through the UI persists it',
            await cdp.eval(`document.body.innerText.includes('UI Task')`));

        const statusChange = await cdp.eval(`
            (() => {
                const row = [...document.querySelectorAll('li')].find((li) => li.innerText.includes('UI Task'));
                if (!row) return 'row not found';
                const select = row.querySelector('select');
                if (!select) return 'status select not found';
                const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
                setter.call(select, 'done');
                select.dispatchEvent(new Event('change', { bubbles: true }));
                return 'ok';
            })()
        `);
        check('task row exposes an inline status control', statusChange === 'ok', statusChange);

        await cdp.waitFor(
            `[...document.querySelectorAll('li')].some((li) => li.innerText.includes('UI Task') && li.querySelector('select')?.value === 'done')`,
            { label: 'task marked done in the UI' },
        );
        check('changing status in the UI is saved', true);

        // --- 10. dashboard reflects the new records -------------------------
        await cdp.send('Page.navigate', { url: `${BASE}/` });
        await cdp.waitFor(`document.body.innerText.toLowerCase().includes('active projects')`, { label: 'dashboard stats' });

        check(
            'dashboard aggregates reflect the project and task created in the UI',
            await cdp.eval(`document.body.innerText.toLowerCase().includes('1 total') && document.body.innerText.toLowerCase().includes('1 done of 1')`),
            await cdp.eval(`document.body.innerText.replace(/\\s+/g, ' ').slice(0, 160)`),
        );

        // --- 11. no uncaught page errors ------------------------------------
        check('no uncaught exceptions in the browser', pageErrors.length === 0, pageErrors.join(' | '));
    } finally {
        cdp.close();
    }

    const failed = results.filter((r) => !r.passed);
    console.log(`\n${results.length - failed.length}/${results.length} UI checks passed`);
    process.exit(failed.length === 0 ? 0 : 1);
}

main().catch((error) => {
    console.error(`\nUI CHECK CRASHED: ${error.message}`);
    process.exit(2);
});
