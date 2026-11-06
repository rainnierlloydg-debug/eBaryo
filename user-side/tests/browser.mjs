import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runtime = path.join(root, 'tests', 'runtime');
const suffix = randomBytes(6).toString('hex');
const database = `barangay_user_smoke_${suffix}`;
const browserProfile = path.resolve(runtime, `browser-${suffix}`);
const edge = process.env.BROWSER_BINARY || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const php = process.env.PHP_BINARY || 'php';
const adminMode = process.env.ADMIN_BROWSER === '1';
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function port() {
    const server = net.createServer(); await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const result = server.address().port; await new Promise(resolve => server.close(resolve)); return result;
}
function db(mode) {
    if (adminMode && mode === 'cleanup') {
        const cleanup = spawnSync(php, ['tests/admin-db.php', 'cleanup', database], { cwd: root, encoding: 'utf8' });
        assert.equal(cleanup.status, 0, cleanup.stderr || cleanup.stdout);
    }
    const result = spawnSync(php, ['tests/db-tool.php', mode, database], { cwd: root, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr || result.stdout);
    if (adminMode && mode === 'setup') {
        const migration = spawnSync(php, ['tests/admin-db.php', 'setup', database], { cwd: root, encoding: 'utf8' });
        assert.equal(migration.status, 0, migration.stderr || migration.stdout);
    }
    return result.stdout;
}
async function terminate(process) {
    if (!process) return;
    const exited = new Promise(resolve => { process.once('exit', resolve); setTimeout(resolve, 3000); });
    process.kill(); await exited;
}
let browser, server, socket;
const errors = [];
const pending = new Map();
let sequence = 0;
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params }));
    });
}
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    assert.ok(!result.exceptionDetails, result.exceptionDetails?.text); return result.result.value;
}
async function until(expression) {
    for (let attempt = 0; attempt < 100; attempt++) { if (await evaluate(expression)) return; await pause(80); }
    throw Error(`Timed out: ${expression}`);
}
async function navigate(url) {
    await send('Page.navigate', { url }); await until(`location.href === ${JSON.stringify(url)} && document.readyState === 'complete'`);
}
async function click(selector) {
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
}
async function screenshot(name) {
    assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), name + ' horizontal overflow');
    const result = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    fs.writeFileSync(path.join(runtime, name + '.png'), Buffer.from(result.data, 'base64'));
}
try {
    fs.mkdirSync(runtime, { recursive: true }); db('setup'); if (!adminMode) db('seed-browser');
    const httpPort = await port(), debugPort = await port();
    const origin = `http://127.0.0.1:${httpPort}/index.php`;
    server = spawn(php, ['-S', `127.0.0.1:${httpPort}`, 'router.php'], { cwd: root, env: { ...process.env, BARANGAY_DB_NAME: database }, windowsHide: true, stdio: 'ignore' });
    browser = spawn(edge, ['--headless=new', '--no-sandbox', '--disable-gpu', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${browserProfile}`, '--no-first-run', '--no-default-browser-check', '--disable-background-networking', 'about:blank'], { windowsHide: true, stdio: 'ignore' });
    let version;
    for (let n = 0; n < 60; n++) { try { version = await (await fetch(`http://127.0.0.1:${debugPort}/json/version`, { signal: AbortSignal.timeout(500) })).json(); break; } catch { await pause(100); } }
    assert.ok(version, 'Browser started');
    const target = await (await fetch(`http://127.0.0.1:${debugPort}/json/new?about:blank`, { method: 'PUT' })).json();
    socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
    socket.addEventListener('message', ({ data }) => {
        const message = JSON.parse(data);
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
        const callback = pending.get(message.id); if (!callback) return;
        pending.delete(message.id); message.error ? callback.reject(Error(message.error.message)) : callback.resolve(message.result);
    });
    await send('Page.enable'); await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    if (adminMode) {
        const adminOrigin = `http://127.0.0.1:${httpPort}/admin/index.php`;
        await navigate(origin + '?page=login');
        await evaluate("document.querySelector('[name=email]').value='admin@example.test';document.querySelector('[name=password]').value='TestPass123!'");
        await click('button[type=submit]'); await until("location.pathname === '/admin/index.php' && !!document.querySelector('.metrics')");
        await screenshot('admin-dashboard-desktop');
        await navigate(adminOrigin + '?page=requests');
        await click('[data-reveal]'); assert.ok(await evaluate("!document.querySelector('[name=search]').disabled"));
        await evaluate("document.querySelector('[name=search]').value='REQ-000001'"); await click('#track-request button');
        await until("location.search.includes('search=') && document.readyState==='complete'");
        assert.ok(await evaluate("document.querySelector('tbody').textContent.includes('REQ-000001')"));
        await navigate(adminOrigin + '?page=editor&id=1');
        await evaluate("document.querySelector('#document-editor').innerHTML='<h1>Verified certificate</h1><p>Juan Cruz</p>'");
        await click('button[value=print]'); await until("document.querySelector('#message-dialog')?.open");
        assert.equal(await evaluate("document.querySelector('#message-dialog h2').textContent"),'Saved Successfully');
        await click('#message-dialog a'); await until("!!document.querySelector('#print-document')");
        assert.ok(await evaluate("document.querySelector('#document-editor').textContent.includes('Verified certificate')"));
        await screenshot('admin-editor-desktop');
        await navigate(adminOrigin+'?page=documents');await click('form[data-confirm] button');
        await until("!!document.querySelector('dialog[open]')");await click('dialog[open] button');
        assert.ok(await evaluate("!document.querySelector('dialog[open]')"));
        await navigate(adminOrigin+'?page=notifications');
        await evaluate("document.querySelector('#recipient-type').value='Custom';document.querySelector('#recipient-type').dispatchEvent(new Event('change'))");
        assert.ok(await evaluate("!document.querySelector('#resident-picker').hidden"));
        await click('button[type=submit],button:not([type])');
        await until("!!document.querySelector('.validation-dialog[open]')");await click('.validation-dialog button');
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        for(const page of ['dashboard','requests','residents','documents','notifications','reports','settings','editor&id=1']) {
            await navigate(adminOrigin+'?page='+page);await screenshot('admin-'+page.split('&')[0]+'-mobile');
        }
        await navigate(adminOrigin);await click('[data-dialog="logout-dialog"]');await click('#logout-dialog a');
        await until("!document.querySelector('dialog[open]') && document.readyState==='complete'");
        await click('[data-dialog="logout-dialog"]');await click('#logout-dialog form button');
        await until("location.pathname==='/index.php' && document.querySelector('#message-dialog')?.open");
        assert.ok(await evaluate("document.querySelector('#message-dialog').textContent.includes('Logout Successful')"));
        assert.deepEqual(errors,[]);
        console.log('PASS: admin browser login, search, editor save/print, confirmation No, recipient picker, validation, logout and eight mobile overflow checks.');
    } else {
    await navigate(origin); await screenshot('landing-desktop');
    assert.equal(await evaluate("document.querySelectorAll('.hero .button').length"), 2);
    await navigate(origin + '?page=login'); await click('button[type=submit]');
    await until("document.querySelector('#message-dialog')?.open && document.querySelector('#message-title')?.textContent === 'Invalid'");
    await click('#message-dialog a'); await until("!document.querySelector('#message-dialog') && document.readyState === 'complete'");
    await evaluate("document.querySelector('[name=email]').value='browser@example.test'; document.querySelector('[name=password]').value='Browser123!'");
    await click('button[type=submit]');
    await until("document.querySelector('#message-dialog')?.open && document.querySelector('#message-title')?.textContent === 'Login Success'");
    await click('#message-dialog a'); await until("location.search === '?page=dashboard' && !!document.querySelector('.metrics')");
    await screenshot('dashboard-desktop');
    await navigate(origin + '?page=request&document=1');
    await evaluate("document.querySelector('[name=purpose]').value='Employment'; document.querySelector('[name=additional_information]').value='Local employer'");
    await click('button[type=submit]'); await until("!!document.querySelector('[name=submission_key]')");
    await click('a[href*=focus]'); await until("!!document.querySelector('[name=purpose]')");
    assert.equal(await evaluate("document.querySelector('[name=purpose]').value"), 'Employment');
    await click('button[type=submit]'); await until("!!document.querySelector('[name=submission_key]')");
    await click('button[type=submit]'); await until("document.querySelector('#message-dialog')?.open && document.querySelector('#message-title')?.textContent === 'Request Submitted'");
    await click('#message-dialog a'); await until("!document.querySelector('#message-dialog') && document.querySelector('tbody tr')");
    await navigate(origin + '?page=notifications'); await click('.notification-title');
    await until("document.querySelector('#message-dialog')?.open"); await click('#message-dialog a');
    await until("!!document.querySelector('.notification.read')");
    await navigate(origin + '?page=profile');
    assert.ok(await evaluate("document.querySelector('#profile-phone').readOnly"));
    await click('[data-edit="profile-phone"]');
    assert.equal(await evaluate("document.querySelector('#profile-phone').readOnly"), false);
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    for (const page of ['dashboard', 'request', 'history', 'notifications', 'profile', 'settings', 'register']) {
        await navigate(origin + '?page=' + page); await screenshot(page + '-mobile');
    }
    await navigate(origin + '?page=dashboard'); await click('[data-open="logout-dialog"]');
    assert.ok(await evaluate("document.querySelector('#logout-dialog').open"));
    await click('#logout-dialog a'); await until("location.search === '?page=dashboard' && !document.querySelector('dialog[open]')");
    await click('[data-open="logout-dialog"]'); await click('#logout-dialog button[type=submit]');
    await until("document.querySelector('#message-dialog')?.open && document.querySelector('#message-title')?.textContent === 'Logout Successful'");
    await click('#message-dialog a'); await until("location.search === '?page=login' && !document.querySelector('#message-dialog')");
    assert.deepEqual(errors, []);
    console.log('PASS: real-browser login dialogs, request summary/edit/submit, notification reading, profile edit controls, logout No/Yes, and mobile overflow checks.');
    }
} finally {
    socket?.close(); await terminate(browser); await terminate(server);
    if (fs.existsSync(browserProfile)) {
        assert.equal(path.dirname(browserProfile), runtime);
        assert.match(path.basename(browserProfile), /^browser-[a-f0-9]{12}$/);
        fs.rmSync(browserProfile, { recursive: true, force: true, maxRetries: 10, retryDelay: 300 });
    }
    console.log(db('cleanup'));
}
