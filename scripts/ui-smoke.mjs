import fs from 'node:fs';

// Run against the local app and a browser started with --remote-debugging-port=9222.
const origin = process.env.UI_TEST_URL || 'http://127.0.0.1:8000';
const pages = await (await fetch('http://127.0.0.1:9222/json/list')).json();
const ws = new WebSocket(pages.find((page) => page.type === 'page').webSocketDebuggerUrl);
await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }));
let sequence = 0;
const pending = new Map();
const errors = [];
let onPageLoad;
ws.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Page.loadEventFired') onPageLoad?.();
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    if (pending.has(message.id)) {
        const { resolve, reject } = pending.get(message.id);
        pending.delete(message.id);
        message.error ? reject(new Error(message.error.message)) : resolve(message.result);
    }
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        ws.send(JSON.stringify({ id, method, params }));
    });
}
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
}
async function until(expression) {
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(`Boolean(${expression})`)) return;
        await new Promise((resolve) => setTimeout(resolve, 150));
    }
    throw new Error(`Timed out: ${expression}`);
}
async function navigate(path) {
    let timeout;
    const loaded = new Promise((resolve, reject) => {
        onPageLoad = resolve;
        timeout = setTimeout(() => reject(new Error(`Page load timed out: ${path}`)), 20000);
    });
    await send('Page.navigate', { url: origin + path });
    try { await loaded; } finally { clearTimeout(timeout); onPageLoad = null; }
    await until(`location.pathname === ${JSON.stringify(path)} && document.readyState === 'complete' && document.querySelector('h1, h2')`);
}
async function layout(label) {
    const result = await evaluate(`({width: innerWidth, content: document.documentElement.scrollWidth, heading: document.querySelector('h1, h2')?.textContent})`);
    if (result.content > result.width + 1) throw new Error(`${label}: horizontal overflow ${JSON.stringify(result)}`);
    console.log(label, JSON.stringify(result));
}
async function screenshot(name) {
    await evaluate('document.fonts.ready.then(() => true)');
    const result = await send('Page.captureScreenshot', { format: 'png' });
    fs.mkdirSync('storage/app/ui-preview', { recursive: true });
    fs.writeFileSync(`storage/app/ui-preview/${name}.png`, Buffer.from(result.data, 'base64'));
}
try {
    await send('Runtime.enable');
    await send('Page.enable');
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await send('Network.clearBrowserCookies');
    await navigate('/');
    await layout('Desktop landing');
    await navigate('/login');
    await until(`document.querySelector('[name=password]')`);
    if (!await evaluate(`document.querySelector('[name=password]').type === 'password' && !document.querySelector('.password-toggle')`)) throw new Error('Login password control differs from the flowchart');
    const env = fs.readFileSync('.env', 'utf8');
    const value = (key) => env.match(new RegExp(`^${key}=(.*)$`, 'm'))?.[1].trim().replace(/^"|"$/g, '');
    const credentials = { email: value('ADMIN_EMAIL'), password: value('ADMIN_PASSWORD') };
    const login = await evaluate(`fetch('/api/login', {method:'POST', credentials:'same-origin', headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}, body:JSON.stringify(${JSON.stringify(credentials)})}).then(async response => ({status:response.status, redirect:(await response.json()).redirect}))`);
    if (login.status !== 200 || login.redirect !== '/admin') throw new Error(`Admin login failed: ${JSON.stringify(login)}`);
    console.log('Admin login: PASS');
    for (const path of ['/admin', '/admin/requests', '/admin/residents', '/admin/documents', '/admin/reports', '/admin/settings']) {
        await navigate(path);
        await until(`document.querySelector('.topbar')`);
        await layout(`Desktop ${path}`);
        if (path === '/admin') {
            await until(`document.querySelector('.metric strong')?.textContent !== '—'`);
            await screenshot('desktop-admin');
            await evaluate(`document.querySelector('.logout-button').click()`);
            await until(`document.querySelector('[role=alertdialog]')`);
            if (!await evaluate(`document.querySelector('[role=alertdialog]').contains(document.activeElement)`)) throw new Error('Dialog focus was not moved');
            await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
            await until(`!document.querySelector('[role=alertdialog]')`);
            console.log('Confirmation dialog keyboard access: PASS');
        }
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await navigate('/admin');
    await until(`document.querySelector('.sidebar-toggle')`);
    await layout('Mobile admin');
    await screenshot('mobile-admin');
    await evaluate(`document.querySelector('.sidebar-toggle').click()`);
    await until(`document.querySelector('.navigation-backdrop')`);
    await evaluate(`document.querySelector('.navigation-backdrop').click()`);
    await until(`!document.querySelector('.sidebar')`);
    console.log('Mobile navigation: PASS');
    for (const path of ['/admin/requests', '/admin/residents', '/admin/documents', '/admin/settings']) {
        await navigate(path);
        await layout(`Mobile ${path}`);
    }
    await send('Network.clearBrowserCookies');
    for (const path of ['/', '/login', '/register']) {
        await navigate(path);
        if (path !== '/') await until(`document.querySelector('.auth-form-wrap')`);
        await layout(`Mobile ${path}`);
    }
    await screenshot('mobile-register');
    if (errors.length) throw new Error(`Browser exceptions: ${errors.join(', ')}`);
    console.log('Browser smoke checks: PASS');
} finally {
    ws.close();
}
