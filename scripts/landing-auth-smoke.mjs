import assert from 'node:assert/strict';
import fs from 'node:fs';

// Use a dedicated headless browser with remote debugging on port 9222.
const origin = process.env.UI_TEST_URL || 'http://127.0.0.1:8000';
const target = await (await fetch('http://127.0.0.1:9222/json/new?about:blank', { method: 'PUT' })).json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise(resolve => ws.addEventListener('open', resolve, { once: true }));
let id = 0;
const pending = new Map();
const errors = [];
ws.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
    const task = pending.get(message.id);
    if (task) {
        pending.delete(message.id);
        message.error ? task.reject(new Error(message.error.message)) : task.resolve(message.result);
    }
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        pending.set(++id, { resolve, reject });
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
        if (await evaluate(expression)) return;
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error(`Timed out: ${expression}`);
}
async function navigate(path, ready) {
    await send('Page.navigate', { url: origin + path });
    await until(`location.pathname === ${JSON.stringify(path)} && document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(ready)})`);
}
async function capture(name) {
    assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), name + ': horizontal overflow');
    await evaluate('Promise.all([document.fonts.ready, ...document.getAnimations().map(animation => animation.finished)]).then(() => true)');
    fs.mkdirSync('storage/app/ui-preview', { recursive: true });
    const result = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('storage/app/ui-preview/' + name + '.png', Buffer.from(result.data, 'base64'));
}
try {
    await send('Runtime.enable');
    await send('Page.enable');
    for (const width of [1440, 390]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 500 });
        await navigate('/', '.landing-actions');
        await capture('modern-landing-' + width);
        assert.deepEqual(await evaluate(`[...new Set([...document.querySelectorAll('.landing-page a[href]')].map(a => a.getAttribute('href')))] .sort()`), ['/login', '/register']);
        await navigate('/register', '[name=national_id_back]');
        await capture('modern-register-' + width);
        assert.deepEqual(await evaluate(`[...document.querySelectorAll('form input')].map(input => input.name)`), [
            'first_name', 'last_name', 'email', 'phone', 'barangay', 'sitio',
            'national_id_front', 'national_id_back', 'password', 'password_confirmation',
        ]);
        assert.ok(await evaluate(`document.documentElement.scrollWidth <= innerWidth + 1`), 'Registration overflows horizontally');
        assert.equal(await evaluate(`!!document.querySelector('a[href="/forgot-password"]')`), true);
        assert.equal(await evaluate(`document.querySelector('.auth-back').getAttribute('href')`), '/');
        assert.equal(await evaluate(`!!document.querySelector('.auth-story, .auth-links, .fine-print, .password-toggle')`), false);
        await navigate('/forgot-password', 'input[name=email]');
        await capture('forgot-password-email-' + width);
        assert.equal(await evaluate(`document.querySelector('.auth-back').getAttribute('href')`), '/login');
        await evaluate(`window.recoveryFetch = window.fetch; window.fetch = (url, options) => ['/api/forgot-password', '/api/reset-password'].includes(url) ? Promise.resolve(new Response(JSON.stringify({message: url === '/api/forgot-password' ? 'Code sent.' : 'Password updated.'}), {status:200, headers:{'Content-Type':'application/json'}})) : window.recoveryFetch(url, options); const email = document.querySelector('[name=email]'); const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; setter.call(email, 'resident@example.test'); email.dispatchEvent(new Event('input', {bubbles:true}));`);
        await evaluate(`document.querySelector('form').requestSubmit()`);
        await until(`!!document.querySelector('[name=otp]')`);
        assert.equal(await evaluate(`document.querySelector('[name=email]').readOnly`), true);
        assert.equal(await evaluate(`[...document.querySelectorAll('button')].find(button => button.textContent.startsWith('Resend OTP')).disabled`), true);
        await capture('forgot-password-otp-' + width);
        await evaluate(`document.querySelector('[name=otp]').value = '123456'; document.querySelector('[name=password]').value = 'NewPassword123!'; document.querySelector('[name=password_confirmation]').value = 'NewPassword123!'; document.querySelector('form').requestSubmit()`);
        await until(`document.querySelector('h2')?.textContent === 'Password updated'`);
        assert.equal(await evaluate(`document.querySelector('a.button').getAttribute('href')`), '/login');
        await navigate('/login', 'form');
        assert.equal(await evaluate(`document.querySelector('.auth-back').getAttribute('href')`), '/');
        assert.ok(await evaluate(`!!document.querySelector('a[href="/forgot-password"]')`));
        await capture('modern-login-' + width);
        await evaluate(`document.querySelector('form').requestSubmit()`);
        await until(`!!document.querySelector('[role=alertdialog]')`);
        assert.equal(await evaluate(`document.querySelector('#login-result-title').textContent`), 'Invalid');
        assert.ok(await evaluate(`document.querySelector('[role=alertdialog]').contains(document.activeElement)`));
        await evaluate(`document.querySelector('[role=alertdialog] button').click()`);
        await until(`!document.querySelector('[role=alertdialog]')`);
        assert.equal(await evaluate(`location.pathname`), '/login');
        // Backend authentication is covered by PHPUnit; isolate the success dialog here.
        await evaluate(`window.originalFetch = window.fetch; window.fetch = (url, options) => url === '/api/login' ? Promise.resolve(new Response(JSON.stringify({redirect:'/dashboard'}), {status:200, headers:{'Content-Type':'application/json'}})) : window.originalFetch(url, options); document.querySelector('form').requestSubmit()`);
        await until(`document.querySelector('#login-result-title')?.textContent === 'Login Success'`);
        assert.equal(await evaluate(`location.pathname`), '/login');
        fs.mkdirSync('storage/app/ui-preview', { recursive: true });
        await evaluate(`Promise.all([document.fonts.ready, ...document.getAnimations().map(animation => animation.finished)]).then(() => true)`);
        const screenshot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`storage/app/ui-preview/login-result-${width}.png`, Buffer.from(screenshot.data, 'base64'));
        await evaluate(`document.querySelector('[role=alertdialog] button').click()`);
        await until(`location.pathname === '/dashboard'`);
        console.log(`PASS ${width}px: landing choices, registration order, invalid/OK retry, success/OK redirect`);
    }
    assert.deepEqual(errors, []);
} finally {
    await send('Page.close');
    ws.close();
}
