// Run with: node tests/Browser/WhatsAppOutbox.mjs
// Requires Node 22+ and Chrome (or CHROME_BIN). No browser npm packages needed.
// All database writes use a fresh SQLite database in a temporary directory.
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { createServer } from 'node:net';

const root = resolve(import.meta.dirname, '../..');
const temp = mkdtempSync(`${tmpdir()}/hardex-outbox-`);
const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: `${temp}/test.sqlite`,
    DB_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false',
    SYSTEM_OWNER_EMAIL: 'admin@buildmart.test', QUEUE_CONNECTION: 'database', BCRYPT_ROUNDS: '4' };
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, chrome, ws;
let serverLog = '';

try {
    execFileSync('php', ['tests/Browser/fixtures/whatsapp-outbox.php', 'check'], { cwd: root, env, stdio: 'pipe' });
    execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], { cwd: root, env, stdio: 'pipe' });
    const fixtures = JSON.parse(execFileSync('php', ['tests/Browser/fixtures/whatsapp-outbox.php'], { cwd: root, env }));
    const portFinder = createServer();
    await new Promise(resolve => portFinder.listen(0, '127.0.0.1', resolve));
    const port = portFinder.address().port;
    await new Promise(resolve => portFinder.close(resolve));
    const base = `http://127.0.0.1:${port}`;
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.',
        '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'], {
        cwd: `${root}/public`, env: { ...env, APP_URL: base }, stdio: ['ignore', 'pipe', 'pipe'],
    });
    server.stderr.on('data', data => { serverLog = (serverLog + data).slice(-4000); });
    chrome = spawn(process.env.CHROME_BIN || 'google-chrome', [
        '--headless', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=0',
        `--user-data-dir=${temp}/chrome`, 'about:blank',
    ], { stdio: 'ignore' });
    let debugPort;
    for (let i = 0; i < 100; i++) {
        try { debugPort = readFileSync(`${temp}/chrome/DevToolsActivePort`, 'utf8').split('\n')[0]; break; }
        catch { await delay(100); }
    }
    assert.ok(debugPort, 'Chrome must start with remote debugging enabled');
    const targets = await (await fetch(`http://127.0.0.1:${debugPort}/json`)).json();
    ws = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
    let sequence = 0;
    const pending = new Map();
    ws.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (!message.id) return;
        const promise = pending.get(message.id);
        pending.delete(message.id);
        message.error ? promise.reject(message.error) : promise.resolve(message.result);
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        pending.set(++sequence, { resolve, reject });
        ws.send(JSON.stringify({ id: sequence, method, params }));
    });
    const evaluate = async (fn, ...args) => {
        const response = await send('Runtime.evaluate', {
            expression: `(${fn.toString()})(...${JSON.stringify(args)})`, returnByValue: true, awaitPromise: true,
        });
        assert.ok(!response.exceptionDetails, JSON.stringify(response.exceptionDetails));
        return response.result.value;
    };
    const waitFor = async (fn, ...args) => {
        for (let i = 0; i < 200; i++) {
            if (await evaluate(fn, ...args)) return;
            await delay(100);
        }
        const page = await evaluate(() => ({ url: location.href, body: document.body.innerText.slice(-2500),
            components: [...document.querySelectorAll('[wire\\:id]')].map(el => ({ id: el.getAttribute('wire:id'), ready: !!el.__livewire })) }));
        throw new Error(`Browser condition timed out: ${fn}\n${JSON.stringify(page)}`);
    };
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Page.navigate', { url: `${base}/login` });
    await waitFor(() => !!document.querySelector('#email') && !!window.Livewire);
    await evaluate(() => {
        for (const [selector, value] of [['#email', 'admin@buildmart.test'], ['#password', 'password']]) {
            const field = document.querySelector(selector);
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
        [...document.forms].find(form => form.getAttribute('wire:submit') === 'login').requestSubmit();
    });
    await waitFor(() => location.pathname === '/dashboard');

    await send('Page.navigate', { url: `${base}/purchases/create` });
    await waitFor(() => !!document.querySelector('form[wire\\:submit="submitPurchase"]')?.closest('[wire\\:id]')?.__livewire);
    await evaluate(supplier => {
        const field = document.querySelector('select[wire\\:model\\.live="supplier_id"]');
        field.value = String(supplier);
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }, fixtures.supplier);
    await waitFor(id => [...document.querySelectorAll('select[wire\\:change] option')].some(option => option.value === String(id)), fixtures.product);
    await evaluate(product => {
        const field = document.querySelector('select[wire\\:change]');
        field.value = String(product);
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }, fixtures.product);
    await waitFor(id => [...document.querySelectorAll('[wire\\:id]')].some(el => el.__livewire?.$wire.items?.[0]?.product_id === String(id)), fixtures.product);
    await evaluate(() => [...document.querySelectorAll('button[type="submit"]')].find(button => button.textContent.trim() === 'Save as Ordered').click());
    await waitFor(() => location.pathname === '/purchases');
    const inspect = () => JSON.parse(execFileSync('php', ['tests/Browser/fixtures/whatsapp-outbox.php', 'inspect'], { cwd: root, env }));
    let result = inspect();
    assert.equal(result.purchases, 1);
    assert.equal(result.notifications.filter(row => row.notification_type === 'purchase_order_created').length, 1);

    await send('Page.navigate', { url: `${base}/products` });
    await waitFor(() => !!document.querySelector('[wire\\:click="deleteConfirmedProduct"]')?.closest('[wire\\:id]')?.__livewire);
    // Search through the same reactive input used by the product list.
    await evaluate(async () => {
        const el = document.querySelector('[wire\\:click="deleteConfirmedProduct"]').closest('[wire\\:id]');
        await el.__livewire.$wire.$set('search', 'BROWSER-OUTBOX');
    });
    await waitFor(id => !!document.querySelector(`[wire\\:click="confirmDeleteProduct(${id})"]`), fixtures.product);
    await evaluate(id => document.querySelector(`[wire\\:click="confirmDeleteProduct(${id})"]`).click(), fixtures.product);
    await waitFor(() => document.body.innerText.includes('Are you sure you want to delete "Browser Outbox Product"'));
    assert.equal(inspect().deleted, false);
    await evaluate(() => document.querySelector('[wire\\:click="deleteConfirmedProduct"]').click());
    await waitFor(() => document.querySelector('[wire\\:click="deleteConfirmedProduct"]').closest('[wire\\:id]').__livewire.$wire.deleting_product_id === null);
    result = inspect();
    assert.equal(result.deleted, true);
    assert.equal(result.notifications.filter(row => row.notification_type === 'product_deleted').length, 1);
    assert.equal(result.notifications.length, 2);
    for (const row of result.notifications) {
        assert.equal(row.company_id, fixtures.company);
        assert.equal(row.branch_id, fixtures.branch);
        assert.equal(row.recipient_id, fixtures.recipient);
    }
    console.log('PASS: Chrome PO form submission and product delete confirmation create tenant-scoped outbox rows for a branch-scoped user and company-wide recipient.');
} catch (error) {
    console.error(serverLog);
    throw error;
} finally {
    ws?.close();
    server?.kill();
    chrome?.kill();
    // Chrome may still be flushing its profile; wait before removing test artifacts.
    await delay(500);
    rmSync(temp, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
}
