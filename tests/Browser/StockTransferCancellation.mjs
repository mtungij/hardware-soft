// Run with: node tests/Browser/StockTransferCancellation.mjs
// Requires Node 22+ and Chrome (or CHROME_BIN). No browser npm packages needed.
// All database writes use a fresh SQLite database in a temporary directory.
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { createServer } from 'node:net';

const root = resolve(import.meta.dirname, '../..');
const temp = mkdtempSync(`${tmpdir()}/hardex-cancel-`);
const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: `${temp}/test.sqlite`,
    DB_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false',
    SYSTEM_OWNER_EMAIL: 'admin@buildmart.test' };
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, chrome, ws;
let serverLog = '';

try {
    execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], { cwd: root, env, stdio: 'pipe' });
    const fixtures = JSON.parse(execFileSync('php', ['tests/Browser/fixtures/stock-transfer-cancellation.php'], { cwd: root, env }));
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
    const paused = [];
    ws.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (message.method === 'Fetch.requestPaused') paused.push(message.params);
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

    const open = async id => {
        await send('Page.navigate', { url: `${base}/stock-transfers/${id}` });
        await waitFor(() => !!document.querySelector('#cancellation-reason')?.closest('[wire\\:id]')?.__livewire);
        await evaluate(() => {
            window.cancelForm = () => [...document.forms].find(form => form.getAttribute('wire:submit') === 'cancelTransfer');
            window.cancelCalls = 0;
            window.cancelClosed = 0;
            window.cancelNavigated = 0;
            Livewire.hook('commit', ({ commit }) => {
                window.cancelCalls += commit.calls.filter(call => call.method === 'cancelTransfer').length;
            });
            window.addEventListener('close-modal', () => window.cancelClosed++);
            document.addEventListener('livewire:navigating', () => window.cancelNavigated++);
            [...document.querySelectorAll('button')].find(button => button.getAttribute('wire:click') === 'openCancellation').click();
        });
        await waitFor(() => getComputedStyle(cancelForm().parentElement.parentElement).display !== 'none');
    };
    const submit = () => evaluate(() => cancelForm().querySelector('button[type="submit"]').click());
    const fill = (reason, confirmed) => evaluate((reason, confirmed) => {
        const field = document.querySelector('#cancellation-reason');
        field.value = reason;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        const checkbox = cancelForm().querySelector('input[type="checkbox"]');
        if (checkbox.checked !== confirmed) checkbox.click();
    }, reason, confirmed);
    const errors = () => evaluate(() => [...cancelForm().querySelectorAll('[role="alert"]')].map(node => node.textContent.trim()));

    await open(fixtures.success);
    await submit();
    await waitFor(() => cancelForm().querySelectorAll('[role="alert"]').length === 2);
    assert.equal(await evaluate(() => window.cancelCalls), 1, 'Invalid forms must reach Livewire');
    assert.equal(await evaluate(() => window.cancelClosed), 0);
    assert.equal(await evaluate(() => location.pathname), `/stock-transfers/${fixtures.success}`);
    assert.equal((await errors()).length, 2);
    await fill('Browser correction', false);
    await submit();
    await waitFor(() => cancelForm().querySelectorAll('[role="alert"]').length === 1);
    assert.equal(await evaluate(() => cancelForm().querySelector('input[type="checkbox"]').checked), false);

    await fill('Browser correction', true);
    await send('Fetch.enable', { patterns: [{ urlPattern: '*livewire/update', requestStage: 'Request' }] });
    await submit();
    for (let i = 0; i < 100 && !paused.length; i++) await delay(50);
    assert.equal(paused.length, 1, 'The confirmed form must send a Livewire request');
    await waitFor(() => cancelForm().querySelector('button[type="submit"]').disabled);
    assert.equal(await evaluate(() => [...cancelForm().querySelectorAll('span')]
        .some(node => node.textContent.trim() === 'Cancelling...' && getComputedStyle(node).display !== 'none')), true);
    await submit(); // Disabled buttons must ignore additional clicks.
    await delay(150);
    assert.equal(paused.length, 1);
    await send('Fetch.continueRequest', { requestId: paused[0].requestId });
    await send('Fetch.disable');
    await waitFor(() => location.pathname === '/stock-transfers' && document.body.innerText.includes('BROWSER-SUCCESS'));
    assert.equal(await evaluate(() => window.cancelClosed), 1);
    assert.equal(await evaluate(() => window.cancelNavigated), 1, 'Success must use Livewire navigation');
    assert.equal(await evaluate(() => document.body.innerText.includes('Stock transfer cancelled. Stock movements have been reversed.')), true);
    assert.equal(await evaluate(() => [...document.querySelectorAll('tr')]
        .find(row => row.textContent.includes('BROWSER-SUCCESS')).textContent.includes('Cancelled')), true);

    await open(fixtures.insufficient);
    await fill('Cannot return sold stock', true);
    await submit();
    await waitFor(() => cancelForm().textContent.includes('Insufficient destination stock'));
    assert.equal(await evaluate(() => location.pathname), `/stock-transfers/${fixtures.insufficient}`);
    assert.equal(await evaluate(() => window.cancelClosed), 0);
    assert.equal(await evaluate(() => window.cancelNavigated), 0);
    assert.equal(await evaluate(() => getComputedStyle(cancelForm().parentElement.parentElement).display !== 'none'), true);
    assert.equal(await evaluate(() => cancelForm().textContent.includes('Available: 0')), true);
    await waitFor(() => !cancelForm().querySelector('button[type="submit"]').disabled);
    console.log('PASS: Chrome cancellation flow (inline validation, explicit confirmation, loading, double-click protection, modal close, success flash, Livewire redirect, cancelled list status, insufficient-stock failure).');
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
