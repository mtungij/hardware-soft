// Run with: node tests/Browser/RolesOriginalLayout.mjs
// Requires Node 22+ and Chrome (or CHROME_BIN). No browser npm packages needed.
// Uses additive migrations and synthetic fixtures in a new temporary SQLite database.
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { createServer } from 'node:net';

const root = resolve(import.meta.dirname, '../..');
const temp = mkdtempSync(`${tmpdir()}/hardex-roles-ui-`);
const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: `${temp}/test.sqlite`,
    DB_URL: '', CACHE_STORE: 'array', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', APP_LOCALE: 'en' };
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let server, chrome, ws;
let serverLog = '';

try {
    writeFileSync(env.DB_DATABASE, '');
    execFileSync('php', ['artisan', 'migrate', '--force'], { cwd: root, env, stdio: 'pipe' });
    const fixtures = JSON.parse(execFileSync('php', ['tests/Browser/fixtures/roles-ui.php'], { cwd: root, env }));
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
    // The local development server uses HTTP; the application normally enforces HTTPS.
    await send('Page.setBypassCSP', { enabled: true });
    await send('Runtime.enable');
    await send('Network.setCookie', { name: 'hardex_staff_locale', value: 'en', url: base });
    await send('Page.navigate', { url: `${base}/login` });
    await waitFor(() => !!document.querySelector('#email')?.closest('[wire\\:id]')?.__livewire);
    await evaluate(async email => {
        const wire = document.querySelector('#email').closest('[wire\\:id]').__livewire.$wire;
        wire.$set('form.email', email, false);
        wire.$set('form.password', 'password', false);
        await wire.$call('login');
    }, fixtures.email);
    await waitFor(() => location.pathname === '/dashboard' && !!document.querySelector('main'));
    await delay(1000);
    await send('Network.setCookie', { name: 'hardex_staff_locale', value: 'en', url: base });


    let checks = 0;
    const check = (condition, message) => { assert.ok(condition, message); checks++; console.log(`PASS: ${message}`); };
    for (const locale of ['en', 'sw']) {
        await evaluate(async locale => {
            await fetch(`/staff/language/${locale}`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
        }, locale);
        for (const width of [1440, 768, 390]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 600 });
            await send('Page.navigate', { url: `${base}/roles` });
            await waitFor(() => !!document.querySelector('form[wire\\:submit="save"]')?.closest('[wire\\:id]')?.__livewire);
            await delay(250);
            check(await evaluate(() => !!document.querySelector('table') && !document.querySelector('input[type="search"]') && !!document.querySelector('form[wire\\:submit="save"]')), `${locale} ${width}px original form and table are present without redesigned controls`);
            check(await evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${locale} ${width}px no page overflow`);
            const layout = await evaluate(() => {
                const inputs = [...document.querySelectorAll('input[wire\\:model="permissions"]')];
                return {
                    aligned: inputs.every(input => {
                        const label = input.closest('label').getBoundingClientRect();
                        const box = input.getBoundingClientRect();
                        const text = input.nextElementSibling.getBoundingClientRect();
                        return text.left >= box.right + 5 && text.right <= label.right + 1 && text.bottom <= label.bottom + 1;
                    }),
                    contained: inputs.every(input => {
                        const text = input.nextElementSibling;
                        return text.scrollWidth <= text.clientWidth + 1;
                    }),
                    twoColumns: inputs.every(input => getComputedStyle(input.closest('label').parentElement).gridTemplateColumns.split(' ').length === 2),
                    localized: document.body.innerText.includes(document.documentElement.lang === 'sw' ? 'Ruhusu Punguzo' : 'Apply Discount'),
                    wraps: inputs.some(input => input.value.startsWith('custom.long_permission_') && input.nextElementSibling.getBoundingClientRect().height > 40),
                };
            });
            check(layout.aligned && layout.contained, `${locale} ${width}px checkbox labels and descriptions do not overlap or escape their containers`);
            check(layout.twoColumns && layout.wraps && layout.localized, `${locale} ${width}px original two-column groups wrap long localized text`);
            check(!await evaluate(() => document.body.innerText.includes('Production Manager')), `${locale} ${width}px manufacturing templates stay hidden for retail`);
            const screenshot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
            writeFileSync(`${temp}/roles-${locale}-${width}.png`, Buffer.from(screenshot.data, 'base64'));
        }
    }
    await evaluate(async role => {
        const wire = document.querySelector('form[wire\\:submit="save"]').closest('[wire\\:id]').__livewire.$wire;
        await wire.$call('editRole', role);
        wire.$set('permissions', ['sales.create', 'products.view', 'sales.discount'], false);
        await wire.$call('save');
        await wire.$call('editRole', role);
    }, fixtures.role);
    check(await evaluate(() => document.querySelector('input[value="sales.discount"]').checked), 'original Edit and Save actions preserve permission selections');
    console.log(JSON.stringify({ passed: checks, failed: 0, screenshots: temp }));
} catch (error) {
    console.error(serverLog);
    throw error;
} finally {
    ws?.close(); server?.kill(); chrome?.kill();
}
