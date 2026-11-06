import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import net from 'node:net';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const database = `barangay_user_smoke_${randomBytes(6).toString('hex')}`;
const php = process.env.PHP_BINARY || 'php';
function db(mode, ...args) {
    const result = spawnSync(php, ['tests/db-tool.php', mode, database, ...args], { cwd: root, encoding: 'utf8' });
    if (result.status !== 0) throw Error(result.stderr || result.stdout || 'Database test command failed.');
    return result.stdout;
}
const listener = net.createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const origin = `http://127.0.0.1:${port}`;
let server;
let logs = '';
let checks = 0;
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64');
class Browser {
    cookie = '';
    async request(url, options = {}) {
        const response = await fetch(origin + '/' + url, { ...options, redirect: 'manual', headers: { Cookie: this.cookie, ...(options.headers || {}) } });
        for (const value of response.headers.getSetCookie()) this.cookie = value.split(';')[0];
        return { status: response.status, location: response.headers.get('location'), body: await response.text() };
    }
    async get(url = 'index.php') { return this.request(url); }
    async post(action, values = {}, files = {}, tokenPage = 'index.php?page=login') {
        const page = await this.get(tokenPage);
        const token = /name="csrf" value="([^"]+)"/.exec(page.body)?.[1];
        assert.ok(token, `Missing CSRF token on ${tokenPage}`);
        const form = new FormData(); form.set('csrf', token); form.set('action', action);
        for (const [name, value] of Object.entries(values)) form.set(name, value);
        for (const [name, file] of Object.entries(files)) form.set(name, new Blob([file.data], { type: file.type }), file.name);
        return this.request('action.php', { method: 'POST', body: form });
    }
    async follow(response) { assert.equal(response.status, 303); return this.get(response.location); }
}
function check(condition, message) { assert.ok(condition, message); checks++; }
function registration(email, overrides = {}) {
    return { first_name: 'Juan', last_name: 'Dela Cruz', email, contact_number: '09123456789', barangay: 'Victoria', sitio: 'Centro', password: 'Resident123!', password_confirmation: 'Resident123!', ...overrides };
}
const files = { national_id_front: { data: image, name: 'front.png', type: 'image/png' }, national_id_back: { data: image, name: 'back.png', type: 'image/png' } };
try {
    db('setup');
    server = spawn(php, ['-d', 'upload_max_filesize=6M', '-d', 'post_max_size=14M', '-S', `127.0.0.1:${port}`, 'router.php'], { cwd: root, env: { ...process.env, BARANGAY_DB_NAME: database }, windowsHide: true });
    server.stderr.on('data', chunk => { logs += chunk; });
    server.on('error', error => { logs += error.message; });
    for (let n = 0; n < 60; n++) {
        try { await fetch(origin); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    const resident = new Browser();
    const landing = await resident.get();
    check(landing.status === 200 && landing.body.includes('page=login') && landing.body.includes('page=register'), 'Landing routes');
    for (const page of ['dashboard', 'request', 'history', 'notifications', 'profile', 'settings']) {
        check((await resident.get(`index.php?page=${page}`)).location === 'index.php?page=login', `${page} is protected`);
    }
    check((await resident.request('action.php', { method: 'POST', body: new URLSearchParams({ action: 'login' }) })).status === 419, 'Missing CSRF rejected');
    for (const credentials of [{}, { email: 'bad', password: 'x' }, { email: "a' OR 1=1 --@example.test", password: 'x' }]) {
        const page = await resident.follow(await resident.post('login', credentials));
        check(page.body.includes('id="message-title">Invalid</h2>'), 'Invalid login dialog');
    }
    let page = await resident.follow(await resident.post('register', registration('one@example.test'), {}, 'index.php?page=register'));
    check(page.body.includes('id="message-title">Invalid</h2>'), 'Missing ID files rejected');
    page = await resident.follow(await resident.post('register', registration('one@example.test', { password_confirmation: 'WrongPassword' }), files, 'index.php?page=register'));
    check(page.body.includes('id="message-title">Invalid</h2>'), 'Password mismatch rejected');
    const invalidFiles = { ...files, national_id_back: { data: Buffer.from('<?php echo 1;'), name: 'back.png', type: 'image/png' } };
    page = await resident.follow(await resident.post('register', registration('one@example.test'), invalidFiles, 'index.php?page=register'));
    check(page.body.includes('Only JPG and PNG'), 'Spoofed image rejected');
    const oversized = { ...files, national_id_back: { data: Buffer.alloc(5 * 1024 * 1024 + 1), name: 'large.png', type: 'image/png' } };
    page = await resident.follow(await resident.post('register', registration('one@example.test'), oversized, 'index.php?page=register'));
    check(page.body.includes('allowed size'), 'Oversized image rejected');
    check(JSON.parse(db('inspect')).users.length === 0, 'Invalid registrations create no accounts');
    page = await resident.follow(await resident.post('register', registration('one@example.test'), files, 'index.php?page=register'));
    check(page.body.includes('Registration Successful'), 'Registration success dialog');
    let state = JSON.parse(db('inspect'));
    check(state.users[0].status === 'Pending' && state.users[0].password_hash !== 'Resident123!' && state.user_preferences.length === 1, 'Pending account, hashed password, preferences');
    page = await resident.follow(await resident.post('login', { email: 'one@example.test', password: 'Resident123!' }));
    check(page.body.includes('id="message-title">Invalid</h2>'), 'Pending login blocked');
    page = await resident.follow(await resident.post('register', registration('one@example.test'), files, 'index.php?page=register'));
    check(page.body.includes('already registered'), 'Duplicate email blocked');
    db('approve', 'one@example.test');
    page = await resident.follow(await resident.post('login', { email: 'one@example.test', password: 'Resident123!' }));
    check(page.body.includes('Login Success') && page.body.includes('href="index.php?page=dashboard"'), 'Login success with dashboard continuation');
    page = await resident.get('index.php?page=dashboard');
    check(page.body.includes('Pending requests') && page.body.includes('Unread notifications'), 'Dashboard summaries');
    page = await resident.get('index.php?page=request&search=Missing');
    check(page.body.includes("Document Doesn't Exist"), 'Missing document error');
    db('deactivate-document');
    page = await resident.get('index.php?page=request&document=3');
    check(page.body.includes("Document Doesn't Exist"), 'Inactive document blocked');
    page = await resident.get('index.php?page=request&search=Barangay%20Clearance');
    check(page.body.includes('name="purpose"') && page.body.includes('Processing time'), 'Search loads active document information');
    page = await resident.follow(await resident.post('review_request', { document_id: 1, purpose: '', additional_information: '' }, {}, 'index.php?page=request&document=1'));
    check(page.body.includes('Invalid Input'), 'Purpose validation');
    page = await resident.follow(await resident.post('review_request', { document_id: 1, purpose: 'Employment', additional_information: '' }, {}, 'index.php?page=request&document=1'));
    check(page.body.includes('Invalid Input'), 'Additional information validation');
    const purpose = 'Employment <script>alert(1)</script>';
    page = await resident.follow(await resident.post('review_request', { document_id: 1, purpose, additional_information: 'For a local employer.' }, {}, 'index.php?page=request&document=1'));
    check(page.body.includes('REQUEST SUMMARY') && page.body.includes('&lt;script&gt;') && !page.body.includes('<script>alert(1)</script>'), 'Summary escapes user input');
    const key = /name="submission_key" value="([^"]+)"/.exec(page.body)[1];
    page = await resident.get('index.php?page=request&document=1&focus=purpose');
    check(page.body.includes('For a local employer.') && page.body.includes('Employment &lt;script&gt;'), 'Back and Edit retains data');
    page = await resident.follow(await resident.post('submit_request', { submission_key: key }, {}, 'index.php?page=request&document=1&stage=summary'));
    check(page.body.includes('Request Submitted') && page.body.includes('Pending'), 'Submission success and Pending history');
    state = JSON.parse(db('inspect'));
    check(state.document_requests.length === 1 && state.notifications.length === 1, 'Request and notification persisted together');
    await resident.post('submit_request', { submission_key: key }, {}, 'index.php?page=request');
    check(JSON.parse(db('inspect')).document_requests.length === 1, 'Repeated submission cannot duplicate');
    page = await resident.follow(await resident.post('open_notification', { notification_id: state.notifications[0].id }, {}, 'index.php?page=notifications'));
    check(page.body.includes('Request Submitted') && JSON.parse(db('inspect')).notifications[0].read_at !== null, 'Notification opening marks read');
    page = await resident.follow(await resident.post('save_profile', { first_name: '', last_name: 'Cruz', email: 'one@example.test', contact_number: '09123456789' }, {}, 'index.php?page=profile'));
    check(page.body.includes('id="message-title">Invalid</h2>'), 'Invalid profile blocked');
    page = await resident.follow(await resident.post('save_profile', { first_name: 'Maria', last_name: 'Cruz', email: 'updated@example.test', contact_number: '09987654321' }, { profile_picture: { data: image, name: 'photo.png', type: 'image/png' } }, 'index.php?page=profile'));
    check(page.body.includes('Save Success') && page.body.includes('Maria Cruz'), 'Profile update and image upload');
    check((await resident.get('media.php')).status === 200, 'Authenticated profile image');
    state = JSON.parse(db('inspect'));
    check((await resident.get('uploads/' + state.users[0].national_id_front)).status === 404, 'ID paths inaccessible');
    check((await resident.get('uploads/' + state.users[0].profile_picture)).status === 404, 'Raw profile image path inaccessible');
    page = await resident.follow(await resident.post('change_password', { current_password: 'wrong', new_password: 'NewResident123!', confirm_password: 'NewResident123!' }, {}, 'index.php?page=settings'));
    check(page.body.includes('id="message-title">Invalid</h2>'), 'Wrong current password blocked');
    page = await resident.follow(await resident.post('change_password', { current_password: 'Resident123!', new_password: 'NewResident123!', confirm_password: 'NewResident123!' }, {}, 'index.php?page=settings'));
    check(page.body.includes('New Password Saved!'), 'Password change saved');
    page = await resident.follow(await resident.post('save_preferences', { in_app_notifications: '1' }, {}, 'index.php?page=settings'));
    check(page.body.includes('Saved Preferences'), 'Preference save dialog');
    state = JSON.parse(db('inspect'));
    check(state.user_preferences[0].email_updates === 0 && state.user_preferences[0].in_app_notifications === 1, 'Both preference values saved');
    const other = new Browser();
    await other.post('register', registration('two@example.test'), files, 'index.php?page=register');
    db('approve', 'two@example.test');
    await other.post('login', { email: 'two@example.test', password: 'Resident123!' });
    page = await other.get('index.php?page=history');
    check(!page.body.includes('REQ-000001'), 'Request history ownership');
    check((await other.post('open_notification', { notification_id: 1 }, {}, 'index.php?page=notifications')).status === 404, 'Notification ownership');
    await other.post('read_all', {}, {}, 'index.php?page=notifications');
    await resident.post('read_all', {}, {}, 'index.php?page=notifications');
    page = await resident.get('index.php?page=notifications');
    check(page.body.includes('notification read'), 'Read notifications have grey state');
    page = await resident.follow(await resident.post('logout', {}, {}, 'index.php?page=dashboard'));
    check(page.body.includes('Logout Successful'), 'Logout success');
    check((await resident.get('index.php?page=dashboard')).location === 'index.php?page=login', 'Logout destroys authenticated session');
    page = await resident.follow(await resident.post('login', { email: 'updated@example.test', password: 'Resident123!' }));
    check(page.body.includes('id="message-title">Invalid</h2>'), 'Old password no longer works');
    page = await resident.follow(await resident.post('login', { email: 'updated@example.test', password: 'NewResident123!' }));
    check(page.body.includes('Login Success'), 'New password works');
    check(!/PHP (Warning|Fatal error|Parse error|Deprecated)/.test(logs), 'No PHP runtime warnings');
    console.log(`PASS: ${checks} HTTP/MySQL checks, using the supplied schema.`);
} finally {
    if (server) {
        server.kill();
        await new Promise(resolve => { if (server.exitCode !== null) resolve(); else { server.once('exit', resolve); setTimeout(resolve, 2000); } });
    }
    console.log(db('cleanup'));
}
