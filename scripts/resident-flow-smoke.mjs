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
const mockApi = () => {
    const state = { profile: { id: 1, role: 'resident', name: 'Juan Cruz', email: 'juan@example.test', phone: '09123456789', address: 'Centro, Poblacion', profile_photo_url: '/favicon.ico', notification_preferences: { email: false, in_app: false } }, unread: true };
    window.testRequests = [];
    const originalFetch = window.fetch;
    window.fetch = async (url, options = {}) => {
        if (!String(url).startsWith('/api/')) return originalFetch(url, options);
        const method = options.method || 'GET';
        const body = options.body instanceof FormData ? Object.fromEntries(options.body) : options.body ? JSON.parse(options.body) : null;
        window.testRequests.push({ url, method, body });
        let result = {};
        let status = 200;
        if (url === '/api/me' || url === '/api/profile') {
            if (method !== 'GET') Object.assign(state.profile, body);
            result = state.profile;
        } else if (url === '/api/dashboard') result = {total_requests:3, unread_notifications:1};
        else if (url.startsWith('/api/documents')) result = url.includes('Missing') ? [] : [{id:1, name:'Clearance', description:'Barangay clearance', fee:50, requirements:['Valid ID']}];
        else if (url === '/api/requests') { result = {id:1, tracking_number:'TEST-001'}; status = 201; }
        else if (url === '/api/password') {
            if (body.current_password !== 'password') { status = 422; result = {errors:{current_password:['The current password is incorrect.']}}; }
            else result = {message:'Password Saved'};
        } else if (url === '/api/notification-preferences') { state.profile.notification_preferences = body.preferences; result = {preferences:body.preferences}; }
        else if (url === '/api/notifications') result = {data:[{id:1, title:'Request update', message:'Your request is ready.', created_at:'2026-10-21T00:00:00Z', read_at:state.unread ? null : '2026-10-21'}]};
        else if (url.startsWith('/api/notifications/')) state.unread = false;
        else if (url !== '/api/logout') throw new Error('Unexpected API request: ' + url);
        return new Response(JSON.stringify(result), {status, headers:{'Content-Type':'application/json'}});
    };
};
async function click(text) {
    const clicked = await evaluate('(() => { const button = [...document.querySelectorAll("button, a.button")].find(item => item.textContent.trim() === ' + JSON.stringify(text) + '); button?.click(); return !!button; })()');
    assert.ok(clicked, 'Missing action: ' + text);
}
async function fill(name, value) {
    await evaluate('(() => { const element = document.querySelector(' + JSON.stringify('[name="' + name + '"]') + '); const setter = Object.getOwnPropertyDescriptor(element.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype, "value").set; setter.call(element, ' + JSON.stringify(value) + '); element.dispatchEvent(new Event("input", {bubbles:true})); })()');
}
async function dialog(text) { await until('document.querySelector("[role=alertdialog]")?.textContent.includes(' + JSON.stringify(text) + ')'); }
async function capture(name) {
    assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), name + ': horizontal overflow');
    await evaluate('Promise.all([document.fonts.ready, ...document.getAnimations().map(animation => animation.finished)]).then(() => true)');
    fs.mkdirSync('storage/app/ui-preview', { recursive: true });
    const result = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync('storage/app/ui-preview/' + name + '.png', Buffer.from(result.data, 'base64'));
}
try {
    await send('Runtime.enable'); await send('Page.enable');
    await send('Page.addScriptToEvaluateOnNewDocument', { source: '(' + mockApi.toString() + ')()' });
    for (const width of [1440, 390]) {
        await send('Emulation.setDeviceMetricsOverride', {width, height:1000, deviceScaleFactor:1, mobile:width < 500});
        await navigate('/dashboard', '.resident-flow-actions');
        await capture('modern-dashboard-' + width);
        assert.ok(await evaluate('document.body.textContent.includes("Total requests") && document.body.textContent.includes("Unread notifications")'));
        assert.equal(await evaluate('!!document.querySelector(".welcome-band, .help-band, .page-footer")'), false);
        await navigate('/request-document', '.document-item');
        await click('Search Document');
        await evaluate('document.querySelector(".toolbar input").name = "search"');
        await fill('search', 'Missing'); await click('Search'); await dialog("Document Doesn't Exist"); await click('OK');
        await until('!!document.querySelector(".document-item")'); await click('View and Request Document');
        assert.equal(await evaluate('!!document.querySelector("input[type=file]")'), false);
        await click('Review Request Summary'); await dialog('Invalid Input'); await click('OK');
        await fill('purpose', 'Employment'); await fill('additional_information[note]', 'For a job application');
        await click('Review Request Summary'); await until('!!document.querySelector(".request-summary")');
        await capture('modern-request-summary-' + width);
        assert.ok(await evaluate('document.querySelector(".request-summary").textContent.includes("For a job application")'));
        await click('Go Back and Edit'); assert.equal(await evaluate('document.querySelector("[name=purpose]").value'), 'Employment');
        await click('Review Request Summary'); await click('Submit Request'); await dialog('Request Submitted');
        assert.equal(await evaluate('testRequests.filter(item => item.url === "/api/requests").length'), 1); await click('OK');
        await navigate('/profile', '[name=name]');
        await capture('modern-profile-' + width);
        assert.equal(await evaluate('document.querySelector("[name=name]").disabled || document.querySelector("[name=email]").disabled'), false);
        assert.equal(await evaluate('!!document.querySelector("input[disabled]")'), false);
        await fill('name', 'Juan Updated'); await click('Save'); await dialog('Save Success'); await click('OK');
        await navigate('/settings', '[name=email_updates]');
        await capture('modern-settings-' + width);
        assert.equal(await evaluate('document.querySelector("[name=email_updates]").checked || document.querySelector("[name=in_app_notifications]").checked'), false);
        await evaluate('document.querySelector("[name=email_updates]").click()'); await click('Save Preference'); await dialog('Saved Preferences'); await click('OK');
        await click('Change Password'); await fill('current_password', 'wrong'); await click('Update Password'); await dialog('Invalid'); await click('OK');
        await fill('current_password', 'password'); await fill('password', 'NewSecurePass123!'); await fill('password_confirmation', 'NewSecurePass123!');
        await click('Update Password'); await dialog('Password Saved'); await click('OK');
        await navigate('/notifications', '.notification-item'); await click('Request update'); await dialog('Your request is ready.'); await click('OK');
        await click('Mark All as Read'); await until('!document.querySelector(".notification-item.unread")');
        assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), 'Mobile horizontal overflow');
        if (!await evaluate('!!document.querySelector(".logout-button")')) await evaluate('document.querySelector(".sidebar-toggle").click()');
        await evaluate('document.querySelector(".logout-button").click()'); await dialog('Are you sure'); await click('No');
        assert.equal(await evaluate('location.pathname'), '/notifications');
        await evaluate('document.querySelector(".logout-button").click()'); await click('Yes'); await dialog('Logout Success'); await click('OK');
        await until('location.pathname === "/"');
        console.log('PASS ' + width + 'px: dashboard, search/review/submit, profile, settings, notifications, logout Yes/No');
    }
    assert.deepEqual(errors, []);
} finally { await send('Page.close'); ws.close(); }
