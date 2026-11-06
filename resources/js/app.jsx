import React, { createContext, useContext, useEffect, useId, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './resident-admin-modal.css';
import './sidebar-layout.css';
import './overview.css';

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const ConfirmationContext = createContext(null);

function useConfirm() {
    const confirm = useContext(ConfirmationContext);
    if (!confirm) throw new Error('ConfirmationProvider is missing.');
    return confirm;
}

function ConfirmationProvider({ children }) {
    const [dialog, setDialog] = useState(null);
    const resolver = useRef(null);

    function confirm(options) {
        return new Promise((resolve) => {
            resolver.current = resolve;
            setDialog(options);
        });
    }

    function resolve(accepted) {
        resolver.current?.(accepted);
        resolver.current = null;
        setDialog(null);
    }

    return <ConfirmationContext.Provider value={confirm}>
        {children}
        {dialog && <ConfirmationDialog {...dialog} onResolve={resolve} />}
    </ConfirmationContext.Provider>;
}

function ConfirmationDialog({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false, onResolve }) {
    useEffect(() => {
        function handleKeyDown(event) {
            if (event.key === 'Escape') onResolve(false);
        }
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [onResolve]);

    return <div className="confirmation-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onResolve(false); }}>
        <section className="confirmation-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirmation-title" aria-describedby="confirmation-message">
            <div className="confirm-header"><span className={`confirm-icon ${danger ? 'confirm-danger' : 'confirm-warning'}`} aria-hidden="true">!</span><div><p className="eyebrow">CONFIRM ACTION</p><h2 id="confirmation-title">{title}</h2></div></div>
            <p className="confirm-message" id="confirmation-message">{message}</p>
            <div className="confirm-actions"><button className="button button-outline" type="button" autoFocus onClick={() => onResolve(false)}>{cancelLabel}</button><button className={`button ${danger ? 'button-danger' : 'button-primary'}`} type="button" onClick={() => onResolve(true)}>{confirmLabel}</button></div>
        </section>
    </div>;
}

async function api(path, options = {}) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(options.headers ?? {}) };
    if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const response = await fetch(`/api${path}`, { credentials: 'same-origin', ...options, headers });
    const payload = response.status === 204 ? null : await response.json().catch(() => null);
    if (!response.ok) {
        if (response.status === 419) throw new Error('Your session has expired. Refresh this page, then try again.');
        if (response.status === 401 && !['/login', '/me'].includes(path)) {
            window.location.assign('/login');
            throw new Error('Please sign in again to continue.');
        }
        const attempts = payload?.attempts_remaining === undefined ? '' : ` ${payload.attempts_remaining} verification attempts remaining.`;
        const details = Object.values(payload?.errors ?? {}).flat().join(' ');
        const error = new Error((details || payload?.message || 'Something went wrong. Please try again.') + attempts);
        error.registrationStatus = payload?.registration_status;
        throw error;
    }
    if (options.method === 'PATCH' && /^\/notifications\/(?:read-all|\d+\/read)$/.test(path)) window.dispatchEvent(new Event('notifications-updated'));
    if ((!options.method || options.method === 'GET') && /^\/admin\/(?:requests|reports)\/\d+$/.test(path) || options.method === 'PATCH' && path === '/admin/reports/read-all') window.dispatchEvent(new Event('notifications-updated'));
    return payload;
}

function useLoad(path) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [reload, setReload] = useState(0);
    const [loading, setLoading] = useState(true);
    useEffect(() => {
        let alive = true;
        setLoading(true);
        api(path).then((result) => { if (alive) { setData(result); setError(''); } }).catch((e) => { if (alive) setError(e.message); }).finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [path, reload]);
    return { data, error, loading, refresh: () => setReload((value) => value + 1) };
}

function App() {
    const [user, setUser] = useState(null);
    const [checking, setChecking] = useState(true);
    const path = window.location.pathname;
    useEffect(() => {
        if (path === '/') { setChecking(false); return; }
        api('/me').then(setUser).catch(() => setUser(null)).finally(() => setChecking(false));
    }, [path]);
    if (path === '/') return <LandingPage />;
    if (checking) return <div className="boot" role="status"><span>Loading System</span></div>;
    if (['/forgot-password', '/reset-password'].includes(path)) return <PasswordRecovery />;
    if (!user) return <AuthScreen path={path} />;
    return <ConfirmationProvider>{user.role === 'admin' ? <AdminShell user={user} /> : <ResidentShell user={user} onUserChange={setUser} />}</ConfirmationProvider>;
}

function Icon({ name, className = '' }) {
    const paths = {
        dashboard: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        document: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h5',
        history: 'M3 11a9 9 0 1 1 2.6 7 M3 4v7h7 M12 7v5l3 2',
        bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
        profile: 'M20 21v-2a7 7 0 0 0-14 0v2 M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        settings: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',
        arrow: 'M5 12h14 M13 6l6 6-6 6',
        logout: 'M9 4H4v16h5 M14 8l4 4-4 4 M8 12h10',
        reports: 'M4 3v18h17 M8 16v-5 M13 16V6 M18 16V9',
        building: 'M3 10l9-7 9 7 M5 10h14v11H5z M9 21v-7h6v7 M9 10h.01 M15 10h.01 M2 21h20',
        lock: 'M5 10h14v11H5z M8 10V6a4 4 0 0 1 8 0v4 M12 14v3',
    };
    return <svg className={`ui-icon ${className}`} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d={paths[name] ?? paths.document} /></svg>;
}

function LandingPage() {
    return <div className="landing-page">
        <header className="landing-header">
            <div className="landing-header-inner">
                <div className="landing-brand">
                    <span className="civic-mark"><Icon name="building" /></span>
                    <div>
                    <strong>Barangay Victoria</strong>
                    <span>Document Request System</span>
                    </div>
                </div>
            </div>
        </header>
        <main className="landing-main">
            <section className="landing-hero">
                <div className="landing-copy"><p className="service-kicker">WELCOME TO YOUR COMMUNITY PORTAL</p><h1>Barangay services,<span>closer to home.</span></h1><p className="service-description">Request documents, follow their progress, and stay connected with Barangay Victoria. Everything you need, in one place.</p>
                <div className="landing-actions">
                    <a className="landing-button landing-button-primary" href="/register">Register <Icon name="arrow" /></a>
                    <a className="landing-button landing-button-outline" href="/login">Login <Icon name="lock" /></a>
                </div>
                </div>
                <div className="landing-art" aria-hidden="true"><div className="civic-orbit orbit-one" /><div className="civic-orbit orbit-two" /><div className="civic-emblem"><Icon name="building" /></div><span className="art-document"><Icon name="document" /></span><span className="art-lock"><Icon name="lock" /></span></div>
            </section>
            <section className="service-steps" aria-label="How it works">
                <article><span>01</span><div><h2>Create your account</h2><p>Register and submit your ID for barangay review.</p></div></article>
                <article><span>02</span><div><h2>Request a document</h2><p>Choose a service and review your request before sending.</p></div></article>
                <article><span>03</span><div><h2>Track and collect</h2><p>Follow updates and collect your document when it is ready.</p></div></article>
            </section>
        </main>
        <footer className="service-footer">eBaryo · Helping our community, one request at a time.</footer>
    </div>;
}

function AuthScreen({ path }) {
    const mode = path === '/register' ? 'register' : 'login';
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [loginResult, setLoginResult] = useState(null);
    const loginForm = useRef(null);
    function acknowledgeLogin() {
        if (loginResult?.redirect) { window.location.assign(loginResult.redirect); return; }
        setLoginResult(null);
        loginForm.current?.querySelector('input[name="email"]')?.focus();
    }
    async function submit(event) {
        event.preventDefault();
        if (busy || loginResult) return;
        setError(''); setBusy(true);
        const form = new FormData(event.currentTarget);
        try {
            if (mode === 'register') {
                await api('/register', { method: 'POST', body: form });
                window.location.assign('/');
            } else {
                const result = await api('/login', { method: 'POST', body: JSON.stringify({ email: form.get('email'), password: form.get('password') }) });
                setLoginResult({ title: 'Login Success', message: result.user?.role === 'resident' ? 'Your registration is Approved. You can now access your dashboard.' : 'You have signed in successfully.', redirect: result.redirect });
            }
        } catch (e) {
            if (mode === 'login') setLoginResult({ title: ({ pending: 'Registration Pending', rejected: 'Registration Rejected', deactivated: 'Account Deactivated' })[e.registrationStatus] || 'Unable to sign in', message: e.message });
            else setError(e.message);
        } finally { setBusy(false); }
    }
    return <main className={`auth-layout auth-flow ${mode === 'register' ? 'auth-register' : 'auth-login'}`}>
        <section className="auth-panel">
            <div className="auth-form-wrap">
                <a className="auth-back" href="/">← Back</a>
                <div className="auth-heading-icon"><Icon name={mode === 'register' ? 'profile' : 'lock'} /></div>
                <h2>{mode === 'register' ? 'Registration Form' : 'Login Form'}</h2>
                <form className="form-stack" ref={loginForm} onSubmit={submit} noValidate={mode === 'login'}>
                    {mode === 'register' && <>
                        <Field label="First name"><input name="first_name" autoComplete="given-name" maxLength="75" required /></Field>
                        <Field label="Last name"><input name="last_name" autoComplete="family-name" maxLength="75" required /></Field>
                    </>}
                    <Field label="Email address"><input name="email" type="email" autoComplete="email" required /></Field>
                    {mode === 'login' && <Field label="Password" passwordToggle={false}><input name="password" type="password" autoComplete="current-password" required /></Field>}
                    {mode === 'register' && <>
                        <Field label="Contact number"><input name="phone" type="tel" autoComplete="tel" maxLength="30" required /></Field>
                        <Field label="Barangay"><input name="barangay" autoComplete="address-level3" maxLength="100" required /></Field>
                        <Field label="Sitio"><input name="sitio" maxLength="100" required /></Field>
                        <Field label="National ID (Front)"><input name="national_id_front" type="file" accept="image/jpeg,image/png" required /></Field>
                        <Field label="National ID (Back)"><input name="national_id_back" type="file" accept="image/jpeg,image/png" required /></Field>
                        <Field label="Password" passwordToggle={false}><input name="password" type="password" autoComplete="new-password" minLength="8" required /></Field>
                        <Field label="Confirm password" passwordToggle={false}><input name="password_confirmation" type="password" autoComplete="new-password" minLength="8" required /></Field>
                    </>}
                    {error && <Notice kind="error">{error}</Notice>}
                    <button className="button button-primary button-wide" disabled={busy || !!loginResult}>{busy ? 'Please wait…' : mode === 'register' ? 'Register Account' : 'Login'}</button>
                </form>
                <p className="auth-recovery-link"><a href="/forgot-password">Forgot password?</a></p>
            </div>
        </section>
        {loginResult && <div className="confirmation-backdrop"><section className="confirmation-dialog" role="alertdialog" aria-modal="true" aria-labelledby="login-result-title" aria-describedby="login-result-message"><h2 id="login-result-title">{loginResult.title}</h2><p id="login-result-message" className="confirm-message">{loginResult.message}</p><div className="confirm-actions"><button className="button button-primary" type="button" autoFocus onClick={acknowledgeLogin}>OK</button></div></section></div>}
    </main>;
}

function PasswordRecovery() {
    const [link] = useState(() => {
        const params = new URLSearchParams(window.location.search);
        const reset = window.location.pathname === '/reset-password';
        const details = { reset, email: reset ? params.get('email') ?? '' : '', token: reset ? params.get('token') ?? '' : '' };
        if (reset) window.history.replaceState(null, '', '/reset-password');
        return details;
    });
    const validLink = link.reset && link.email && /^[A-Za-z0-9]{64}$/.test(link.token);
    const [email, setEmail] = useState(link.email);
    const [sent, setSent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);
    const [resendAt, setResendAt] = useState(0);
    const [seconds, setSeconds] = useState(0);
    useEffect(() => {
        const tick = () => setSeconds(Math.max(0, Math.ceil((resendAt - Date.now()) / 1000)));
        tick();
        const timer = setInterval(tick, 1000);
        return () => clearInterval(timer);
    }, [resendAt]);
    async function sendLink() {
        if (busy || seconds > 0) return;
        setBusy(true); setError(''); setMessage('');
        try {
            const result = await api('/forgot-password', { method: 'POST', body: JSON.stringify({ email }) });
            setSent(true); setMessage(result.message); setResendAt(Date.now() + 60000);
        } catch (e) { setError(e.message); }
        finally { setBusy(false); }
    }
    async function submit(event) {
        event.preventDefault();
        if (!link.reset) { await sendLink(); return; }
        if (busy) return;
        const form = new FormData(event.currentTarget);
        setBusy(true); setError(''); setMessage('');
        try {
            const result = await api('/reset-password', { method: 'POST', body: JSON.stringify({ email, token: link.token, password: form.get('password'), password_confirmation: form.get('password_confirmation') }) });
            setDone(true); setMessage(result.message);
        } catch (e) { setError(e.message); }
        finally { setBusy(false); }
    }
    return <main className="auth-layout auth-flow auth-login"><section className="auth-panel"><div className="auth-form-wrap">
        <a className="auth-back" href="/login">← Back to login</a>
        <div className="auth-heading-icon"><Icon name="lock" /></div>
        <h2>{done ? 'Password updated' : link.reset ? 'Change your password' : sent ? 'Check your email' : 'Forgot password?'}</h2>
        {!done && !link.reset && <p>We will email you a confirmation link. Open it to choose your new password.</p>}
        {message && <p role="status">{message}</p>}
        {error && <Notice kind="error">{error}</Notice>}
        {done ? <a className="button button-primary button-wide" href="/login">Login</a> : link.reset && !validLink ? <><Notice kind="error">This confirmation link is incomplete. Request a new email to continue.</Notice><a className="button button-primary button-wide" href="/forgot-password">Request a new link</a></> : <form className="form-stack" onSubmit={submit}>
            <Field label="Registered email address"><input name="email" type="email" autoComplete="email" maxLength="255" value={email} readOnly={sent || link.reset} onChange={event => setEmail(event.target.value)} required /></Field>
            {link.reset && <>
                <Field label="New password" hint="Use 8–72 characters."><input name="password" type="password" autoComplete="new-password" minLength="8" maxLength="72" required /></Field>
                <Field label="Confirm new password"><input name="password_confirmation" type="password" autoComplete="new-password" minLength="8" maxLength="72" required /></Field>
            </>}
            <button className="button button-primary button-wide" disabled={busy || (!link.reset && seconds > 0)}>{busy ? 'Please wait…' : link.reset ? 'Save new password' : seconds > 0 ? `Resend confirmation in ${seconds}s` : sent ? 'Resend confirmation email' : 'Send confirmation email'}</button>
            {link.reset && <a className="text-link" href="/forgot-password">Request a new confirmation link</a>}
            {sent && !link.reset && <>
                <button className="text-link" type="button" disabled={busy} onClick={() => { setSent(false); setMessage(''); setError(''); }}>Use a different email</button>
            </>}
        </form>}
    </div></section></main>;
}

function PasswordInput(props) {
    const [visible, setVisible] = useState(false);
    return <span className="password-control"><input {...props} type={visible ? 'text' : 'password'} /><button className="password-toggle" type="button" aria-label={visible ? 'Hide password' : 'Show password'} aria-pressed={visible} onClick={() => setVisible((value) => !value)}>{visible ? 'Hide' : 'Show'}</button></span>;
}

function Field({ label, hint, children, passwordToggle = true }) {
    const id = useId();
    const control = React.isValidElement(children) && ['input', 'textarea', 'select'].includes(children.type)
        ? React.cloneElement(children, { id, 'aria-describedby': hint ? `${id}-hint` : undefined }) : children;
    return <div className="field"><label htmlFor={id}>{label}</label>{passwordToggle && control?.type === 'input' && control.props.type === 'password' ? <PasswordInput {...control.props} /> : control}{hint && <small id={`${id}-hint`}>{hint}</small>}</div>;
}
function Notice({ children, kind = 'success' }) { return <div className={`notice notice-${kind}`} role={kind === 'error' ? 'alert' : 'status'}><span className="notice-symbol" aria-hidden="true">{kind === 'error' ? '!' : '✓'}</span><span>{children}</span></div>; }
function Loading() { return <div className="loading-state" role="status"><span className="loading-spinner" aria-hidden="true" />Loading your information…</div>; }

function DialogAccessibility() {
    useEffect(() => {
        const selector = '.modal-panel, .confirmation-dialog, .admin-resident-modal, .document-editor-window';
        let active = null;
        let previous = null;
        const originalOverflow = document.body.style.overflow;
        function sync() {
            const dialogs = [...document.querySelectorAll(selector)];
            const next = dialogs.at(-1) ?? null;
            if (next === active) return;
            if (!next) {
                active = null;
                document.body.style.overflow = originalOverflow;
                if (previous?.isConnected) previous.focus();
                previous = null;
                return;
            }
            if (!active) previous = document.activeElement;
            active = next;
            next.setAttribute('role', next.getAttribute('role') || 'dialog');
            next.setAttribute('aria-modal', 'true');
            if (!next.hasAttribute('aria-label') && !next.hasAttribute('aria-labelledby')) next.setAttribute('aria-label', next.querySelector('h2')?.textContent || 'Details');
            next.setAttribute('tabindex', '-1');
            document.body.style.overflow = 'hidden';
            (next.querySelector('[autofocus], button, input:not([type=hidden]), select, textarea, a[href]') || next).focus();
        }
        function trap(event) {
            if (!active || event.key !== 'Tab') return;
            const items = [...active.querySelectorAll('a[href], button, input, select, textarea, [tabindex="0"]')].filter((element) => !element.disabled && element.getClientRects().length);
            const first = items[0], last = items.at(-1);
            if (!first) { event.preventDefault(); active.focus(); return; }
            if (event.shiftKey && (document.activeElement === first || document.activeElement === active)) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || !active.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
        }
        const observer = new MutationObserver(sync);
        observer.observe(document.getElementById('app'), { childList: true, subtree: true });
        document.addEventListener('keydown', trap);
        sync();
        return () => { observer.disconnect(); document.removeEventListener('keydown', trap); document.body.style.overflow = originalOverflow; };
    }, []);
    return null;
}
function Button({ children, onClick, kind = '', type = 'button', disabled = false }) { return <button type={type} className={`button ${kind}`} onClick={onClick} disabled={disabled}>{children}</button>; }
function FlowMessage({ title, message, onClose, className = '' }) {
    const titleId = useId();
    const messageId = useId();
    return <div className="confirmation-backdrop"><section className={`confirmation-dialog ${className}`} role="alertdialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={message !== title ? messageId : undefined}><h2 id={titleId}>{title}</h2>{message !== title && <p id={messageId}>{message}</p>}<div className="confirm-actions"><Button kind="button-primary" onClick={onClose}>OK</Button></div></section></div>;
}
function Status({ children }) { return <span className={`status status-${String(children).toLowerCase().replaceAll(' ', '-')}`}>{children}</span>; }
function Empty({ children }) { return <div className="empty-state"><span className="empty-mark">—</span><p>{children}</p></div>; }

const residentNav = [
    ['/dashboard', 'Dashboard', '01'], ['/request-document', 'Request Document', '02'], ['/request-history', 'Request History', '03'], ['/notifications', 'Notifications', '04'], ['/profile', 'My Profile', '05'], ['/settings', 'Settings', '06'],
];
const adminNav = [
    ['/admin', 'Overview', '01'], ['/admin/requests', 'Document requests', '02'], ['/admin/residents', 'Residents', '03'], ['/admin/documents', 'Documents', '04'], ['/admin/notifications', 'Notifications', '05'], ['/admin/reports', 'Reports', '06'], ['/admin/settings', 'Settings', '07'],
];

function Shell({ user, admin = false, children }) {
    const confirm = useConfirm();
    const [logoutMessage, setLogoutMessage] = useState('');
    const [unreadCount, setUnreadCount] = useState(0);
    const [unreadRequests, setUnreadRequests] = useState(0);
    useEffect(() => {
        let active = true;
        let sequence = 0;
        async function refreshUnread() {
            if (document.hidden) return;
            const request = ++sequence;
            try {
                const result = await api(admin ? '/admin/navigation-counts' : '/notifications/unread-count');
                if (active && request === sequence) { setUnreadCount(admin ? result.notifications : result.count); setUnreadRequests(admin ? result.requests : 0); }
            } catch { /* Keep the last known count until the next refresh. */ }
        }
        refreshUnread();
        const timer = window.setInterval(refreshUnread, 30000);
        window.addEventListener('focus', refreshUnread);
        window.addEventListener('notifications-updated', refreshUnread);
        document.addEventListener('visibilitychange', refreshUnread);
        return () => { active = false; window.clearInterval(timer); window.removeEventListener('focus', refreshUnread); window.removeEventListener('notifications-updated', refreshUnread); document.removeEventListener('visibilitychange', refreshUnread); };
    }, [admin, user.id]);
    function badgeCount(href) {
        if (href.includes('notification')) return unreadCount;
        if (admin && href === '/admin/requests') return unreadRequests;
        return 0;
    }
    const links = admin ? adminNav : residentNav;
    const current = window.location.pathname;
    const [sidebarOpen, setSidebarOpen] = useState(() => window.innerWidth > 720 && window.localStorage.getItem('ebaryo-sidebar-hidden') !== 'true');
    const title = links.find(([href]) => href === current)?.[1] ?? (admin ? 'Admin workspace' : 'Resident services');
    function toggleSidebar() {
        setSidebarOpen((open) => {
            if (window.innerWidth > 720) window.localStorage.setItem('ebaryo-sidebar-hidden', open ? 'true' : 'false');
            return !open;
        });
    }
    useEffect(() => {
        document.title = `${title} | eBaryo`;
        function escape(event) { if (event.key === 'Escape' && window.innerWidth <= 720) setSidebarOpen(false); }
        window.addEventListener('keydown', escape);
        return () => window.removeEventListener('keydown', escape);
    }, [title]);
    async function logout() {
        if (!await confirm({ title: admin ? 'Sign out?' : 'Logout', message: 'Are you sure you want to log out?', confirmLabel: admin ? 'Sign out' : 'Yes', cancelLabel: admin ? 'Cancel' : 'No', danger: true })) return;
        try { await api('/logout', { method: 'POST' }); setLogoutMessage('Logout Success'); }
        catch (e) { setLogoutMessage(e.message); }
    }
    return <div className={`shell collapsible-shell ${sidebarOpen ? '' : 'sidebar-hidden'}`}>
        <a className="skip-link" href="#main-content">Skip to content</a>
        {sidebarOpen && <button className="navigation-backdrop" type="button" aria-label="Close navigation" onClick={() => setSidebarOpen(false)} />}
        <aside className="sidebar" id="main-sidebar">
            <div className="sidebar-brand-row"><a className="brand brand-side" href={admin ? '/admin' : '/dashboard'}><span className="brand-mark">e</span><span>eBaryo</span></a><button className="sidebar-toggle sidebar-toggle-inside" type="button" onClick={toggleSidebar} aria-label={sidebarOpen ? 'Collapse navigation' : 'Expand navigation'} aria-controls="sidebar-links" aria-expanded={sidebarOpen} title={sidebarOpen ? 'Collapse navigation' : 'Expand navigation'}><span className="burger-icon" aria-hidden="true"><i /><i /><i /></span></button></div>
            <div className="side-label">{admin ? 'ADMINISTRATION' : 'RESIDENT PORTAL'}</div>
            <nav className="side-nav" id="sidebar-links" aria-label={admin ? 'Administration' : 'Resident services'}>{links.map(([href, label, number]) => <a key={href} className={`${current === href ? 'active' : ''} ${badgeCount(href) > 0 ? 'has-unread' : ''}`} aria-current={current === href ? 'page' : undefined} href={href} title={sidebarOpen ? undefined : `${label}${badgeCount(href) > 0 ? ` (${badgeCount(href)} unread)` : ''}`} aria-label={`${label}${badgeCount(href) > 0 ? `, ${badgeCount(href)} unread items` : ''}`}><Icon name={href.includes('reports') ? 'reports' : href.includes('history') ? 'history' : href.includes('notification') ? 'bell' : href.includes('profile') || href.includes('resident') ? 'profile' : href.includes('settings') ? 'settings' : href.includes('document') || href.includes('requests') ? 'document' : 'dashboard'} /><span>{label}</span>{badgeCount(href) > 0 && <b className="nav-unread-badge" aria-hidden="true">{badgeCount(href) > 99 ? '99+' : badgeCount(href)}</b>}<span className="nav-arrow">↗</span></a>)}</nav>
            <div className="sidebar-bottom"><div className="user-chip" title={user.name}><span className="avatar">{user.name?.slice(0, 1).toUpperCase()}</span><span className="sidebar-user-details"><b>{user.name}</b><small>{admin ? 'Barangay admin' : 'Resident account'}</small></span></div><button type="button" className="logout-button" onClick={logout} title={sidebarOpen ? undefined : 'Logout'} aria-label="Logout"><Icon name="logout" /><span>Logout</span></button></div>
        </aside>
        <main className="main-area"><header className="topbar"><div className="topbar-title"><div>{admin && <p className="eyebrow">ADMIN CONSOLE</p>}<h1>{title}</h1></div></div>{admin && <a className="topbar-profile" href="/admin/settings" aria-label="Open admin profile and settings"><span className="topbar-profile-avatar">{user.profile_photo_url ? <img src={user.profile_photo_url} alt="" /> : user.name?.slice(0, 1).toUpperCase()}</span><span className="topbar-profile-copy"><b>{user.name}</b><small>Barangay admin</small></span></a>}</header><div className="page-content" id="main-content" tabIndex="-1">{children}</div>{admin && <footer className="page-footer"><span>eBaryo · Document Request System</span><span>Barangay Hall · Victoria, Oriental Mindoro</span></footer>}</main>
        {logoutMessage && <FlowMessage title={logoutMessage} message={logoutMessage} onClose={() => { if (logoutMessage === 'Logout Success') window.location.assign(admin ? '/login' : '/'); else setLogoutMessage(''); }} />}
    </div>;
}

function ResidentShell({ user, onUserChange }) {
    const path = window.location.pathname;
    let page;
    if (path === '/request-document') page = <RequestDocument />;
    else if (path === '/request-history') page = <RequestHistory />;
    else if (path === '/notifications') page = <ResidentNotifications />;
    else if (path === '/profile') page = <Profile onSaved={profile => onUserChange(current => ({ ...current, ...profile }))} />;
    else if (path === '/settings') page = <ResidentSettings />;
    else page = <ResidentDashboard user={user} />;
    return <Shell user={user}>{page}</Shell>;
}

function ResidentDashboard({ user }) {
    const { data, error, loading } = useLoad('/dashboard');
    const today = new Date();
    const metrics = [['Total requests', data?.total_requests, 'document', 'Your submitted document requests'], ['Unread notifications', data?.unread_notifications, 'bell', 'Updates waiting to be read']];
    return <div className="overview-page">
        <section className="overview-welcome"><div className="overview-welcome-copy"><span className="overview-kicker">YOUR RESIDENT WORKSPACE</span><h2>Welcome back, {user?.name || 'Resident'}.</h2><p>Welcome to eBaryo, your connection to barangay services. Keep track of your document requests and stay informed about updates from your barangay office.</p></div><div className="overview-date"><Icon name="building" /><time dateTime={today.toLocaleDateString('en-CA')}><span>{today.toLocaleDateString('en-PH', { weekday: 'long' })}</span><strong>{today.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })}</strong></time></div></section>
        {error && <Notice kind="error">{error}</Notice>}
        <div className="overview-section-heading"><h3>Your activity at a glance</h3><span>Resident summary</span></div>
        <section className="overview-metrics" aria-label="Resident summary">{metrics.map(([label, count, icon, hint]) => <article className="overview-metric" key={label}><div className="overview-metric-top"><span>{label}</span><span className="overview-metric-icon"><Icon name={icon} /></span></div><strong>{loading || count == null ? '—' : Number(count).toLocaleString()}</strong><small>{hint}</small></article>)}</section>
        <SystemInformation />
    </div>;
}
function RequestRow({ record, onClick }) {
    const Element = onClick ? 'button' : 'a';
    const destination = window.location.pathname.startsWith('/admin') ? `/admin/requests?request=${record.id}` : '/request-history';
    return <Element className="request-row" {...(onClick ? { onClick, type: 'button' } : { href: destination })}><span className="request-id">{record.tracking_number}</span><span className="request-title">{record.document_snapshot?.name}</span><span className="request-date">{new Date(record.submitted_at).toLocaleDateString()}</span><Status>{record.status}</Status><span className="row-arrow">↗</span></Element>;
}

function DocumentTemplateThumbnail({ document, onOpen }) {
    const host = useRef(null);
    const [scale, setScale] = useState(0.2);
    useEffect(() => {
        const element = host.current;
        if (!element || !document.templates_exists) return;
        function resize() { setScale(Math.max(0.1, element.clientWidth / 816)); }
        resize();
        const observer = new ResizeObserver(resize);
        observer.observe(element);
        return () => observer.disconnect();
    }, [document.id, document.templates_exists]);
    return <button type="button" ref={host} className="document-template-thumbnail" aria-label={`View ${document.name} template and request details`} onClick={onOpen}>{document.templates_exists ? <><span className="document-thumbnail-paper" style={{ width: 816 * scale, height: 1154 * scale }}><iframe src={`/api/documents/${document.id}/template`} title={`${document.name} template thumbnail`} sandbox="" loading="lazy" tabIndex={-1} style={{ transform: `scale(${scale})` }} /></span><span className="document-thumbnail-caption">View template</span></> : <span className="document-template-placeholder"><Icon name="document" /><span>Template not yet available</span></span>}</button>;
}
function RequestDocument() {
    const [searchOpen, setSearchOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const { data: documents, error, loading } = useLoad('/documents?search=' + encodeURIComponent(query));
    const [selected, setSelected] = useState(null);
    const [purpose, setPurpose] = useState('');
    const [additional, setAdditional] = useState('');
    const [review, setReview] = useState(false);
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState(null);
    function reviewRequest(event) {
        event.preventDefault();
        if (!purpose.trim() || !additional.trim()) { setDialog({ title: 'Invalid Input', message: 'Enter a purpose and additional information.' }); return; }
        setReview(true);
    }
    async function submit() {
        if (busy) return;
        setBusy(true);
        try {
            await api('/requests', { method: 'POST', body: JSON.stringify({ document_id: selected.id, purpose: purpose.trim(), additional_information: { note: additional.trim() } }) });
            setDialog({ title: 'Request Submitted', message: 'Request Submitted', submitted: true });
        } catch (e) { setDialog({ title: 'Invalid Input', message: e.message }); }
        finally { setBusy(false); }
    }
    function closeDialog() {
        if (dialog?.submitted) { setSelected(null); setReview(false); setPurpose(''); setAdditional(''); }
        setDialog(null);
    }
    return <>
        {error && <Notice kind="error">{error}</Notice>}
        {!selected && <><div className="section-heading"><h2>Available Documents</h2><Button kind="button-outline" onClick={() => setSearchOpen(true)}>Search Document</Button></div>
            {searchOpen && <form className="toolbar" onSubmit={(event) => { event.preventDefault(); setQuery(search.trim()); }}><Field label="Document name"><input value={search} onChange={event => setSearch(event.target.value)} /></Field><Button type="submit" kind="button-primary">Search</Button></form>}
            {loading ? <Loading /> : documents?.length ? <div className="document-grid">{documents.map(document => <article className="document-item document-template-card" key={document.id}><DocumentTemplateThumbnail document={document} onOpen={() => { setSelected(document); setPurpose(''); setAdditional(''); }} /><h3>{document.name}</h3><Button kind="button-outline" onClick={() => { setSelected(document); setPurpose(''); setAdditional(''); }}>View and Request Document<Icon name="arrow" /></Button></article>)}</div> : !error && <><Empty>No available documents.</Empty>{query && <FlowMessage title="Document Doesn't Exist" message="Document Doesn't Exist" onClose={() => { setQuery(''); setSearch(''); }} />}</>}
        </>}
        {selected && <section className="form-panel">
            <h2>{selected.name}</h2>{selected.templates_exists && <section className="resident-template-preview"><h3>Document template</h3><iframe title={`${selected.name} template preview`} src={`/api/documents/${selected.id}/template`} sandbox="" />{selected.word_template_exists && <a className="text-link" href={`/api/documents/${selected.id}/template?download=1`}>Download Word template</a>}</section>}
            {!review ? <><p>{selected.description}</p><p>Fee: PHP {Number(selected.fee).toFixed(2)}</p><form className="form-stack" noValidate onSubmit={reviewRequest}><Field label="Purpose"><input name="purpose" value={purpose} maxLength="500" onChange={event => setPurpose(event.target.value)} required /></Field><Field label="Additional information"><textarea name="additional_information[note]" value={additional} maxLength="1000" onChange={event => setAdditional(event.target.value)} required /></Field><Button type="submit" kind="button-primary">Review Request Summary</Button></form></> : <div className="request-summary"><h3>Request Summary</h3><dl><dt>Document</dt><dd>{selected.name}</dd><dt>Purpose</dt><dd>{purpose}</dd><dt>Additional information</dt><dd>{additional}</dd><dt>Fee</dt><dd>PHP {Number(selected.fee).toFixed(2)}</dd></dl><div className="resident-flow-actions"><Button kind="button-outline" onClick={() => setReview(false)} disabled={busy}>Go Back and Edit</Button><Button kind="button-primary" onClick={submit} disabled={busy}>{busy ? 'Submitting...' : 'Submit Request'}</Button></div></div>}
        </section>}
        {dialog && <FlowMessage {...dialog} onClose={closeDialog} />}
    </>;
}

function RequestHistory() {
    const confirm = useConfirm();
    const { data, error, loading, refresh } = useLoad('/requests');
    const [selected, setSelected] = useState(null);
    const [cancelReason, setCancelReason] = useState('');
    const [customReason, setCustomReason] = useState('');
    const [problem, setProblem] = useState('');
    const [notice, setNotice] = useState('');
    const records = data?.data ?? [];
    async function cancelRequest(record) {
        const reason = cancelReason === 'Other' ? customReason : cancelReason;
        if (!reason.trim()) { setProblem('Select a reason or enter a custom reason.'); return; }
        if (!await confirm({ title: 'Cancel this request?', message: `Cancel ${record.tracking_number}? This action cannot be undone.`, confirmLabel: 'Cancel request', danger: true })) return;
        try { await api(`/requests/${record.id}/cancel`, { method: 'PATCH', body: JSON.stringify({ reason_type: cancelReason, custom_reason: cancelReason === 'Other' ? customReason : null }) }); setNotice('Request cancelled.'); setSelected(null); setCancelReason(''); setCustomReason(''); refresh(); }
        catch (e) { setProblem(e.message); }
    }
    return <>
        <div className="section-heading"><div><p className="eyebrow">ALL SUBMISSIONS</p><h2>Request history</h2></div><span className="count-note">{data?.total ?? 0} requests</span></div>
        {notice && <Notice>{notice}</Notice>}{problem && <Notice kind="error">{problem}</Notice>}{error && <Notice kind="error">{error}</Notice>}
        {loading && !data ? <Loading /> : records.length ? <div className="data-list">{records.map((record) => <RequestRow key={record.id} record={record} onClick={() => { setSelected(record); setProblem(''); }} />)}</div> : <Empty>No requests yet. Your submitted documents will appear here.</Empty>}
        {selected && <div className="modal-backdrop" onClick={() => setSelected(null)}><section className="modal-panel" onClick={(e) => e.stopPropagation()}><div className="modal-heading"><div><p className="eyebrow">REQUEST DETAILS</p><h2>{selected.tracking_number}</h2></div><button className="icon-button" onClick={() => setSelected(null)} aria-label="Close">×</button></div><div className="detail-grid"><span>Document<b>{selected.document_snapshot?.name}</b></span><span>Purpose<b>{selected.purpose}</b></span><span>Fee<b>₱{Number(selected.fee).toFixed(2)}</b></span><span>Payment<b>{selected.payment_status}</b></span><span>Submitted<b>{new Date(selected.submitted_at).toLocaleString()}</b></span><span>Status<b><Status>{selected.status}</Status></b></span></div>
            {selected.rejection_reason && <Notice kind="error">Reason: {selected.rejection_reason}</Notice>}{selected.correction_instruction && <Notice>Correction requested: {selected.correction_instruction}</Notice>}
            <h3 className="subheading">Timeline</h3><div className="timeline">{(selected.timeline ?? []).map((item) => <div className="timeline-item" key={item.id}><i /><div><b>{item.status}</b><small>{new Date(item.created_at).toLocaleString()}</small>{item.note && <p>{item.note}</p>}</div></div>)}</div>
            {selected.status === 'Under Review' && <div className="cancel-panel"><Field label="Reason for cancellation"><select value={cancelReason} onChange={(e) => setCancelReason(e.target.value)}><option value="">Choose a reason</option><option>Submitted by mistake</option><option>No longer needed</option><option>Will submit later</option><option>Other</option></select></Field>{cancelReason === 'Other' && <Field label="Your reason"><textarea value={customReason} onChange={(e) => setCustomReason(e.target.value)} rows="2" placeholder="Tell us why you are cancelling" /></Field>}<Button onClick={() => cancelRequest(selected)} kind="button-danger">Cancel request</Button></div>}
            {selected.status === 'For Correction' && <CorrectionForm record={selected} onDone={() => { setSelected(null); refresh(); }} />}
        </section></div>}
    </>;
}

function CorrectionForm({ record, onDone }) {
    const confirm = useConfirm();
    const [purpose, setPurpose] = useState(record.purpose);
    const [problem, setProblem] = useState('');
    async function submit(event) {
        event.preventDefault(); const form = new FormData(event.currentTarget); if ((record.correction_fields ?? []).includes('purpose')) form.set('purpose', purpose); else form.delete('purpose');
        if (!await confirm({ title: 'Resubmit corrected request?', message: `Send your corrections for ${record.tracking_number} to the Barangay Admin?`, confirmLabel: 'Resubmit request' })) return;
        try { await api(`/requests/${record.id}/resubmit`, { method: 'POST', body: form }); onDone(); } catch (e) { setProblem(e.message); }
    }
    return <form className="form-stack correction-form" onSubmit={submit}><h3 className="subheading">Make corrections</h3><p>{record.correction_instruction}</p>{(record.correction_fields ?? []).includes('purpose') && <Field label="Purpose"><input value={purpose} onChange={(e) => setPurpose(e.target.value)} required /></Field>}{(record.correction_fields ?? []).filter((field) => field !== 'purpose').map((field) => { const index = (record.document_snapshot?.requirements ?? []).indexOf(field); return <Field key={field} label={`Replace ${field}`}><input type="file" name={`requirements[${index}][]`} accept="image/jpeg,image/png" multiple required /></Field>; })}{problem && <Notice kind="error">{problem}</Notice>}<Button type="submit" kind="button-primary">Save corrections and resubmit</Button></form>;
}

function notificationDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return { group: 'Earlier', relative: 'Date unavailable', full: 'Date unavailable' };
    const now = new Date();
    const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
    const day = date.toDateString();
    const group = day === now.toDateString() ? 'Today' : day === yesterday.toDateString() ? 'Yesterday' : date.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
    const seconds = Math.max(0, Math.floor((now - date) / 1000));
    const relative = seconds < 60 ? 'Just now' : seconds < 3600 ? `${Math.floor(seconds / 60)} min ago` : seconds < 86400 ? `${Math.floor(seconds / 3600)} hr ago` : seconds < 604800 ? `${Math.floor(seconds / 86400)} days ago` : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    return { group, relative, full: date.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) };
}

function NotificationFeed({ items, onOpen }) {
    const groups = new Map();
    for (const item of items) {
        const date = notificationDate(item.created_at);
        if (!groups.has(date.group)) groups.set(date.group, []);
        groups.get(date.group).push({ item, date });
    }
    return <div className="social-notification-feed">{[...groups].map(([group, entries]) => <section className="notification-date-group" key={group} aria-label={group}><h3>{group}</h3><div className="notification-date-items">{entries.map(({ item, date }) => {
        const unread = Boolean(onOpen && !item.read_at);
        const content = <><span className={`social-note-avatar ${item.priority === 'important' ? 'social-note-important' : ''}`} aria-hidden="true"><Icon name="bell" /></span><span className="social-note-copy"><span className="social-note-source">{onOpen ? 'Barangay office' : `To ${item.resident?.name ?? 'Resident'}`}{item.priority === 'important' && <span className="social-note-priority">Important</span>}</span><strong>{item.title}</strong><span className="social-note-message">{item.message}</span><span className="social-note-date"><span>{date.relative}</span><span aria-hidden="true">·</span><time dateTime={item.created_at} title={date.full}>{date.full}</time></span>{item.documents?.length > 0 && onOpen && <span className="social-note-attachment">{item.documents.length} attachment{item.documents.length > 1 ? 's' : ''}</span>}</span>{unread && <span className="social-note-unread" aria-label="Unread" />}</>;
        return <article className={`social-note ${unread ? 'is-unread' : ''}`} key={item.id}>{onOpen ? <button type="button" className="social-note-body" onClick={() => onOpen(item)} aria-label={`${unread ? 'Unread: ' : ''}${item.title}. ${date.full}`}>{content}</button> : <><div className="social-note-body">{content}</div>{item.documents?.length > 0 && <div className="social-note-files">{item.documents.map(file => <a key={file.url} href={file.url}>{file.name}</a>)}</div>}</>}</article>;
    })}</div></section>)}</div>;
}

function ResidentNotifications({ title = 'Notifications', subtitle = 'Stay updated on your requests and barangay announcements.' }) {
    const [page, setPage] = useState(1);
    const [filter, setFilter] = useState('all');
    const { data, error, loading, refresh } = useLoad(`/notifications?page=${page}${filter === 'unread' ? '&unread=1' : ''}`);
    const [selected, setSelected] = useState(null);
    const [problem, setProblem] = useState('');
    const [busy, setBusy] = useState(false);
    async function openNotification(note) {
        try {
            if (!note.read_at) await api('/notifications/' + note.id + '/read', { method: 'PATCH' });
            setSelected(note); refresh();
        } catch (e) { setProblem(e.message); }
    }
    async function markAllRead() {
        setBusy(true);
        try { await api('/notifications/read-all', { method: 'PATCH' }); refresh(); }
        catch (e) { setProblem(e.message); }
        finally { setBusy(false); }
    }
    return <section className="notifications-inbox"><div className="social-notifications-heading"><div><h2>{title}</h2><p>{subtitle}</p></div><Button kind="button-outline" onClick={markAllRead} disabled={busy}>{busy ? 'Marking as read…' : 'Mark all as read'}</Button></div>
        <div className="notification-filter-bar" role="group" aria-label="Filter notifications">{['all', 'unread'].map(value => <button type="button" key={value} aria-pressed={filter === value} className={filter === value ? 'is-active' : ''} onClick={() => { setFilter(value); setPage(1); }}>{value === 'all' ? 'All' : 'Unread'}</button>)}</div>
        {(error || problem) && <Notice kind="error">{error || problem}</Notice>}
        {loading ? <Loading /> : data?.data?.length ? <NotificationFeed items={data.data} onOpen={openNotification} /> : !error && <div className="social-note-empty"><Icon name="bell" /><h3>{filter === 'unread' ? "You're all caught up" : 'No notifications yet'}</h3><p>{filter === 'unread' ? 'You have no unread notifications.' : 'Updates from your barangay will appear here.'}</p></div>}
        <Pagination data={data} page={page} onChange={setPage} />
        {selected && <FlowMessage title={selected.title} message={<><span className="notification-detail-date">{notificationDate(selected.created_at).full}</span><span className="notification-detail-message">{selected.message}</span>{selected.documents?.map(file => <span className="table-sub" key={file.url}><a href={file.url}>{file.name}</a></span>)}</>} onClose={() => setSelected(null)} />}
    </section>;
}
function Profile({ onSaved }) {
    const { data: profile, loading, error: loadError, refresh } = useLoad('/profile');
    const [photoPreview, setPhotoPreview] = useState('');
    const [photoFailed, setPhotoFailed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState(null);
    useEffect(() => () => { if (photoPreview) URL.revokeObjectURL(photoPreview); }, [photoPreview]);
    useEffect(() => setPhotoFailed(false), [photoPreview, profile?.profile_photo_url]);
    async function save(event) {
        event.preventDefault();
        if (busy) return;
        const formElement = event.currentTarget;
        const form = new FormData(formElement);
        const photo = form.get('photo');
        if (!photo?.size) form.delete('photo');
        if (!form.get('name')?.trim() || !form.get('email')?.trim() || !form.get('phone')?.trim() || (!photo?.size && !profile.profile_photo_url)) {
            setDialog({ title: 'Invalid', message: 'Profile picture, full name, email, and contact number are required.' }); return;
        }
        form.append('_method', 'PATCH');
        setBusy(true);
        try { const saved = await api('/profile', { method: 'POST', body: form }); onSaved(saved); setPhotoPreview(''); formElement.reset(); refresh(); setDialog({ title: 'Save Success', message: 'Save Success' }); }
        catch (e) { setDialog({ title: 'Invalid', message: e.message }); }
        finally { setBusy(false); }
    }
    return <>{loadError && <Notice kind="error">{loadError}</Notice>}{loading ? <Loading /> : profile && <section className="form-panel profile-panel"><h2>My Profile</h2>
        <form className="form-stack" key={profile.updated_at ?? profile.name + profile.email + profile.phone} noValidate onSubmit={save}>
            <div className="profile-picture">{(photoPreview || profile.profile_photo_url) && !photoFailed ? <img src={photoPreview || profile.profile_photo_url} alt="Profile picture" onError={() => setPhotoFailed(true)} /> : <Icon name="profile" />}</div>
            <Field label="Upload profile picture"><input name="photo" type="file" accept="image/jpeg,image/png,image/webp" onChange={event => setPhotoPreview(event.target.files?.[0] ? URL.createObjectURL(event.target.files[0]) : '')} /></Field>
            <Field label="Full name"><input name="name" autoComplete="name" defaultValue={profile.name} maxLength="150" required /></Field>
            <Field label="Email address"><input name="email" type="email" autoComplete="email" defaultValue={profile.email} required /></Field>
            <Field label="Contact number"><input name="phone" type="tel" autoComplete="tel" defaultValue={profile.phone ?? ''} maxLength="30" required /></Field>
            <Button type="submit" kind="button-primary" disabled={busy}>{busy ? 'Saving...' : 'Save'}</Button>
        </form>
    </section>}{dialog && <FlowMessage {...dialog} onClose={() => setDialog(null)} />}</>;
}

function ResidentSettings() {
    const { data: profile, error, loading } = useLoad('/profile');
    const [email, setEmail] = useState(true);
    const [inApp, setInApp] = useState(true);
    const [passwordOpen, setPasswordOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [dialog, setDialog] = useState(null);
    useEffect(() => {
        if (profile) { setEmail(profile.notification_preferences?.email ?? true); setInApp(profile.notification_preferences?.in_app ?? true); }
    }, [profile]);
    async function updatePreferences() {
        setBusy(true);
        try { await api('/notification-preferences', { method: 'PATCH', body: JSON.stringify({ preferences: { email, in_app: inApp } }) }); setDialog({ title: 'Saved Preferences', message: 'Saved Preferences' }); }
        catch (e) { setDialog({ title: 'Invalid', message: e.message }); }
        finally { setBusy(false); }
    }
    async function changePassword(event) {
        event.preventDefault();
        if (busy) return;
        const formElement = event.currentTarget;
        const form = new FormData(formElement);
        setBusy(true);
        try { await api('/password', { method: 'PUT', body: JSON.stringify(Object.fromEntries(form)) }); formElement.reset(); setDialog({ title: 'Password Saved', message: 'Password Saved' }); }
        catch (e) { setDialog({ title: 'Invalid', message: e.message }); }
        finally { setBusy(false); }
    }
    return <>{error && <Notice kind="error">{error}</Notice>}{loading ? <Loading /> : profile && <div className="settings-grid">
        <section className="settings-section"><Button kind="button-outline" onClick={() => setPasswordOpen(true)}>Change Password</Button>
            {passwordOpen && <form className="form-stack" noValidate onSubmit={changePassword}>
                <Field label="Current password" passwordToggle={false}><input name="current_password" type="password" autoComplete="current-password" required /></Field>
                <Field label="New password" passwordToggle={false}><input name="password" type="password" autoComplete="new-password" minLength="8" required /></Field>
                <Field label="Confirm password" passwordToggle={false}><input name="password_confirmation" type="password" autoComplete="new-password" minLength="8" required /></Field>
                <Button type="submit" kind="button-primary" disabled={busy}>Update Password</Button>
            </form>}
        </section>
        <section className="settings-section"><label className="toggle-row"><span>Email updates</span><input name="email_updates" type="checkbox" checked={email} disabled={busy} onChange={event => setEmail(event.target.checked)} /></label><label className="toggle-row"><span>In-app notifications</span><input name="in_app_notifications" type="checkbox" checked={inApp} disabled={busy} onChange={event => setInApp(event.target.checked)} /></label><Button onClick={updatePreferences} kind="button-primary" disabled={busy}>Save Preference</Button></section>
    </div>}{dialog && <FlowMessage {...dialog} onClose={() => setDialog(null)} />}</>;
}

function AdminShell({ user }) {
    const path = window.location.pathname;
    const views = { '/admin': <AdminDashboard user={user} />, '/admin/requests': <AdminRequests />, '/admin/residents': <AdminResidents />, '/admin/documents': <AdminDocuments />, '/admin/notifications': <AdminNotifications />, '/admin/reports': <AdminReports />, '/admin/settings': <AdminSettings user={user} /> };
    return <Shell user={user} admin>{views[path] ?? <AdminDashboard user={user} />}</Shell>;
}

function SystemInformation() {
    return <>
        <section className="overview-about" aria-labelledby="about-ebaryo"><div className="overview-about-heading"><span className="overview-about-mark" aria-hidden="true">e</span><div><span className="overview-kicker">ABOUT THE SYSTEM</span><h3 id="about-ebaryo">Bringing barangay services closer to the community.</h3></div></div><p>eBaryo is a barangay document request system that connects residents with their barangay office. It brings registration, document requests, and status updates into one organized workspace.</p><div className="overview-info-grid">{[['profile', 'Resident verification', 'Registration details and National ID images help the admin review and verify resident accounts.'], ['document', 'Document management', 'Word templates and a document editor help prepare the documents requested by residents.'], ['bell', 'Community updates', 'Notifications keep residents informed about their requests and barangay announcements.']].map(([icon, title, description]) => <article key={title}><span className="overview-info-icon"><Icon name={icon} /></span><h4>{title}</h4><p>{description}</p></article>)}</div></section>
        <section className="overview-process" aria-labelledby="overview-process-title"><div className="overview-section-heading"><h3 id="overview-process-title">From registration to document release</h3><span>How eBaryo works</span></div><div className="overview-process-grid">{[['Register', 'Residents submit their information and National ID for review.'], ['Verify', 'The admin checks the submitted details and approves valid registrations.'], ['Request', 'Approved residents select a document and submit their request.'], ['Prepare & release', 'The admin processes the request and notifies the resident when it is ready for pickup.']].map(([title, description], index) => <article key={title}><span className="overview-step-number">0{index + 1}</span><h4>{title}</h4><p>{description}</p></article>)}</div></section>
    </>;
}

function AdminDashboard({ user }) {
    const { data, error, loading } = useLoad('/admin/dashboard');
    const today = new Date();
    const metrics = [
        ['Total requests', data?.total_requests, 'document', 'All document requests'],
        ['Pending registrations', data?.pending_registrations, 'profile', 'Awaiting your approval'],
    ];
    return <div className="overview-page">
        <section className="overview-welcome"><div className="overview-welcome-copy"><span className="overview-kicker">BARANGAY ADMINISTRATION</span><h2>Welcome back, {user?.name || 'Admin'}.</h2><p>Welcome to your barangay workspace. eBaryo helps organize resident registrations, document requests, and community updates.</p></div><div className="overview-date"><Icon name="building" /><time dateTime={today.toLocaleDateString('en-CA')}><span>{today.toLocaleDateString('en-PH', { weekday: 'long' })}</span><strong>{today.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' })}</strong></time></div></section>
        {error && <Notice kind="error">{error}</Notice>}
        <div className="overview-section-heading"><h3>Your barangay at a glance</h3><span>Live overview</span></div>
        <section className="overview-metrics" aria-label="Barangay summary">{metrics.map(([label, count, icon, hint]) => <article className="overview-metric" key={label}><div className="overview-metric-top"><span>{label}</span><span className="overview-metric-icon"><Icon name={icon} /></span></div><strong>{loading ? '—' : count == null ? '—' : Number(count).toLocaleString()}</strong><small>{hint}</small></article>)}</section>
        <SystemInformation />
    </div>;
}
function AdminRequests() {
    const [search, setSearch] = useState(() => new URLSearchParams(window.location.search).get('search') ?? ''); const [searchInput, setSearchInput] = useState(search); const [status, setStatus] = useState(''); const [documentId, setDocumentId] = useState(''); const [paymentStatus, setPaymentStatus] = useState(''); const [date, setDate] = useState(''); const [selectedId, setSelectedId] = useState(() => new URLSearchParams(window.location.search).get('request')); const [archived, setArchived] = useState(false); const [page, setPage] = useState(1); const [reload, setReload] = useState(0);
    const { data: documents } = useLoad('/admin/documents');
    const { data, error, loading } = useLoad(`/admin/requests?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&document_id=${documentId}&payment_status=${paymentStatus}&date=${date}&archived=${archived ? 1 : 0}&page=${page}&refresh=${reload}`);
    const refresh = () => setReload((value) => value + 1);
    useEffect(() => { setPage(1); }, [search, status, documentId, paymentStatus, date, archived]);
    return <><div className="section-heading"><div><p className="eyebrow">REVIEW QUEUE</p><h2>Document requests</h2></div><span className="count-note">{data?.total ?? 0} records</span></div><form className="toolbar" onSubmit={event => { event.preventDefault(); setSearch(searchInput.trim()); }}><label className="search-box"><span>⌕</span><input value={searchInput} onChange={(e) => setSearchInput(e.target.value)} placeholder="Resident name or ID" required /></label><Button type="submit" kind="button-primary">Search</Button><select aria-label="Filter by document" value={documentId} onChange={(e) => setDocumentId(e.target.value)}><option value="">All documents</option>{(documents ?? []).map((document) => <option key={document.id} value={document.id}>{document.name}</option>)}</select><select aria-label="Filter by status" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">All statuses</option>{['Submitted', 'Under Review', 'Approved', 'Rejected', 'For Correction', 'Processing', 'Ready for Pickup', 'Released', 'Cancelled', 'Expired'].map((value) => <option key={value}>{value}</option>)}</select><select aria-label="Filter by payment" value={paymentStatus} onChange={(e) => setPaymentStatus(e.target.value)}><option value="">Any payment</option><option>Paid</option><option>Unpaid</option></select><select aria-label="Request files" value={archived ? "archived" : "active"} onChange={e => setArchived(e.target.value === "archived")}><option value="active">Active files</option><option value="archived">Archived files</option></select><input aria-label="Filter by date" type="date" value={date} onChange={(e) => setDate(e.target.value)} /></form>{error && <Notice kind="error">{error}</Notice>}<div className="table-wrap"><table><thead><tr><th>REQUEST</th><th>RESIDENT</th><th>DOCUMENT</th><th>SUBMITTED</th><th>STATUS</th><th>PAYMENT</th><th>FEE</th></tr></thead><tbody>{(data?.data ?? []).map((record) => <tr key={record.id} onClick={() => setSelectedId(record.id)}><td className="mono"><button type="button" className="table-link" onClick={(event) => { event.stopPropagation(); setSelectedId(record.id); }} aria-label={`Open request ${record.tracking_number}`}>{record.tracking_number}</button></td><td>{record.resident?.name}</td><td>{record.document_snapshot?.name}</td><td>{new Date(record.submitted_at).toLocaleDateString()}</td><td><Status>{record.status}</Status></td><td><Status>{record.payment_status}</Status></td><td>₱{Number(record.fee).toFixed(2)}</td></tr>)}</tbody></table>{loading && !data ? <Loading /> : !error && !data?.data?.length && <Empty>No requests found. Try changing your filters.</Empty>}</div><Pagination data={data} page={page} onChange={setPage} />{selectedId && <AdminRequestDetail id={selectedId} close={() => setSelectedId(null)} refresh={refresh} />}</>;
}

function AdminRequestDetail({ id, close, refresh }) {
    const confirm = useConfirm();
    const [editorOpen, setEditorOpen] = useState(false);
    const { data: record, error, refresh: refreshRecord } = useLoad(`/admin/requests/${id}`); const [status, setStatus] = useState(''); const [reason, setReason] = useState(''); const [instruction, setInstruction] = useState(''); const [fields, setFields] = useState([]); const [problem, setProblem] = useState(''); const [notice, setNotice] = useState(''); const [amount, setAmount] = useState(''); const [paidAt, setPaidAt] = useState('');
    if (!record) return <div className="modal-backdrop" onClick={close}><section className="modal-panel" onClick={(e) => e.stopPropagation()}>{error || 'Loading request…'}</section></div>;
    const options = record.status === 'Submitted' ? ['Under Review', 'Cancelled'] : record.status === 'Under Review' ? ['Approved', 'Rejected', 'For Correction', 'Cancelled'] : record.status === 'Approved' ? ['Processing', 'Cancelled'] : record.status === 'Processing' ? ['Ready for Pickup', 'Cancelled'] : record.status === 'Ready for Pickup' ? ['Released'] : [];
    const canRecordPayment = record.payment_status !== 'Paid' && (['Ready for Pickup', 'Released'].includes(record.status) || ['Ready for Pickup', 'Released'].includes(status));
    async function saveUpdates(event) {
        event.preventDefault(); setProblem(''); setNotice('');
        if (!status && !amount) { setProblem('Choose a status or enter payment details.'); return; }
        if (amount && !paidAt) { setProblem('Select a payment date.'); return; }
        const changes = [status && `set status to ${status}`, amount && `record ₱${Number(amount).toFixed(2)} payment`].filter(Boolean);
        const payload = { ...(status ? { status, reason, instruction, fields } : {}), ...(amount ? { amount_paid: amount, paid_at: paidAt } : {}) };
        try {
            await api(`/admin/requests/${id}/updates`, { method: 'PATCH', body: JSON.stringify(payload) });
            setNotice([status && `Status updated to ${status}.`, amount && 'Payment recorded.'].filter(Boolean).join(' '));
            setStatus(''); setReason(''); setInstruction(''); setFields([]); setAmount(''); setPaidAt('');
            refresh(); refreshRecord();
        } catch (e) { setProblem(e.message); }
    }
    return <><div className="modal-backdrop" onClick={close}><section className="modal-panel admin-detail" onClick={(e) => e.stopPropagation()}><div className="modal-heading"><div><p className="eyebrow">REQUEST REVIEW</p><h2>{record.tracking_number}</h2></div><button className="icon-button" onClick={close} aria-label="Close">×</button></div><div className="detail-grid"><span>Resident<b>{record.resident_snapshot?.name}</b></span><span>Document<b>{record.document_snapshot?.name}</b></span><span>Address<b>{record.resident_snapshot?.address}</b></span><span>Email<b>{record.resident_snapshot?.email}</b></span><span>Purpose<b>{record.purpose}</b></span><span>Fee / payment<b>₱{Number(record.fee).toFixed(2)} · {record.payment_status}</b></span></div>
        <h3 className="subheading">Requirements</h3><div className="upload-list">{(record.uploads ?? []).map((upload) => <a key={upload.id} href={`/api/uploads/${upload.id}`} target="_blank" rel="noreferrer"><span>{upload.requirement_name}</span><b>{upload.original_name}</b><span>↗</span></a>)}{!record.uploads?.length && <p className="muted">No files attached.</p>}</div>
        {(options.length > 0 || canRecordPayment) && <form className="form-stack action-form" onSubmit={saveUpdates}><h3 className="subheading">Update request</h3>{options.length > 0 && <><Field label="Next status"><select value={status} onChange={(e) => setStatus(e.target.value)}><option value="">Keep current status</option>{options.map((value) => <option key={value}>{value}</option>)}</select></Field>{['Rejected', 'Cancelled'].includes(status) && <Field label="Reason · required"><textarea value={reason} onChange={(e) => setReason(e.target.value)} rows="2" required /></Field>}{status === 'For Correction' && <><Field label="Fields to correct"><select multiple value={fields} onChange={(e) => setFields([...e.target.selectedOptions].map((option) => option.value))} required><option value="purpose">Purpose</option>{(record.document_snapshot?.requirements ?? []).map((field) => <option key={field} value={field}>{field}</option>)}</select></Field><Field label="Instructions · required"><textarea value={instruction} onChange={(e) => setInstruction(e.target.value)} required rows="2" /></Field></>}</>}{canRecordPayment && <div className="payment-form"><h3 className="subheading">Cash payment</h3><Field label="Amount received"><input type="number" step="0.01" min="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} required={Boolean(paidAt)} /></Field><Field label="Payment date"><input type="datetime-local" value={paidAt} onChange={(e) => setPaidAt(e.target.value)} required={Boolean(amount)} /></Field><small>Payment does not block document release.</small></div>}<Button type="submit" kind="button-primary">Save updates</Button></form>}
        {record.status === 'Approved' && <div className="action-row"><Button onClick={() => setEditorOpen(true)} kind="button-primary">Edit request document</Button></div>}
        {problem && <Notice kind="error">{problem}</Notice>}{notice && <Notice>{notice}</Notice>}
    </section></div>{editorOpen && <DocumentEditor id={id} close={() => setEditorOpen(false)} />}</>;
}

function DocumentEditor({ id, close, documentId = null }) {
    const draftPath = documentId ? `/admin/documents/${documentId}/template-draft` : `/admin/requests/${id}/document-draft`;
    const confirm = useConfirm();
    const frameRef = useRef(null);
    const inputHandlerRef = useRef(null);
    const [content, setContent] = useState('');
    const [wordTemplateUrl, setWordTemplateUrl] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [saveState, setSaveState] = useState('');
    const [problem, setProblem] = useState('');

    useEffect(() => {
        let active = true;
        api(draftPath).then((draft) => {
            if (!active) return;
            setContent(draft.content);
            setWordTemplateUrl(draft.word_template_url ?? '');
            setSaveState(draft.saved_at ? `Last saved ${new Date(draft.saved_at).toLocaleString()}` : 'New draft');
        }).catch((error) => { if (active) setProblem(error.message); }).finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [id, documentId]);

    function enableEditing() {
        const body = frameRef.current?.contentDocument?.body;
        if (!body) return;
        body.contentEditable = 'true';
        const handler = () => setDirty(true);
        inputHandlerRef.current = handler;
        body.addEventListener('input', handler);
    }

    function format(command, value = null) {
        const frame = frameRef.current;
        frame?.contentWindow?.focus();
        frame?.contentDocument?.execCommand(command, false, value);
    }

    async function saveDraft() {
        const document = frameRef.current?.contentDocument;
        if (!document?.documentElement) return;
        setSaving(true);
        setProblem('');
        try {
            const savedDocument = document.documentElement.cloneNode(true);
            savedDocument.querySelector('body')?.removeAttribute('contenteditable');
            const result = await api(draftPath, {
                method: 'PUT',
                body: JSON.stringify({ content: savedDocument.outerHTML }),
            });
            setDirty(false);
            setSaveState(`Saved ${new Date(result.saved_at).toLocaleString()}`);
            return true;
        } catch (error) { setProblem(error.message); }
        finally { setSaving(false); }
    }

    async function saveAndPrint() {
        if (await saveDraft()) frameRef.current?.contentWindow?.print();
    }

    async function requestClose() {
        if (dirty && !await confirm({ title: 'Discard unsaved edits?', message: 'Your changes to this request document have not been saved.', confirmLabel: 'Discard edits', danger: true })) return;
        const body = frameRef.current?.contentDocument?.body;
        if (body && inputHandlerRef.current) body.removeEventListener('input', inputHandlerRef.current);
        close();
    }

    return <div className="document-editor-backdrop" onClick={(event) => { event.stopPropagation(); if (event.target === event.currentTarget) requestClose(); }}>
        <section className="document-editor-window" onClick={(event) => event.stopPropagation()}>
            <header className="document-editor-header"><div><p className="eyebrow">REQUEST DOCUMENT</p><h2>{documentId ? 'Edit document template' : 'Edit document draft'}</h2></div><div><button className="icon-button" type="button" onClick={requestClose} aria-label="Close document editor">×</button></div></header>
            <div className="document-editor-toolbar" role="toolbar" aria-label="Document formatting">
                <button className="document-editor-tool" type="button" aria-label="Bold" title="Bold" onClick={() => format('bold')}><b>B</b></button>
                <button className="document-editor-tool" type="button" aria-label="Italic" title="Italic" onClick={() => format('italic')}><i>I</i></button>
                <button className="document-editor-tool" type="button" aria-label="Underline" title="Underline" onClick={() => format('underline')}><u>U</u></button>
                <span className="document-editor-divider" />
                <button className="document-editor-tool" type="button" aria-label="Align left" title="Align left" onClick={() => format('justifyLeft')}>⇤</button>
                <button className="document-editor-tool" type="button" aria-label="Align center" title="Align center" onClick={() => format('justifyCenter')}>↔</button>
                <button className="document-editor-tool" type="button" aria-label="Align right" title="Align right" onClick={() => format('justifyRight')}>⇥</button>
                <select className="document-editor-toolbar-select" aria-label="Font family" defaultValue="Times New Roman" onChange={(event) => format('fontName', event.target.value)}><option>Times New Roman</option><option>Arial</option><option>Calibri</option><option>Georgia</option></select>
                <label className="document-editor-color">Text color<input type="color" aria-label="Text color" defaultValue="#202923" onChange={(event) => format('foreColor', event.target.value)} /></label>
            </div>
            <div className="document-editor-canvas">{loading ? <div className="document-editor-loading">Loading request document…</div> : content && <iframe ref={frameRef} className="document-editor-frame" title="Editable request document" sandbox="allow-same-origin allow-modals" srcDoc={content} onLoad={enableEditing} />}</div>
            <footer className="document-editor-footer"><span className="document-editor-save-state">{problem ? <span className="document-editor-error">{problem}</span> : dirty ? 'Unsaved changes' : saveState}</span><div><Button type="button" kind="button-outline" onClick={requestClose}>Close</Button><Button type="button" kind="button-primary" disabled={loading || saving || !dirty} onClick={saveDraft}>{saving ? 'Saving…' : documentId ? 'Save and publish template' : 'Save draft'}</Button><Button type="button" kind="button-outline" disabled={loading || saving} onClick={saveAndPrint}>Save &amp; print</Button></div></footer>
        </section>
    </div>;
}

function ResidentRegistration({ resident, onClose, onReviewed }) {
    const [busy, setBusy] = useState(false);
    const [confirmApproval, setConfirmApproval] = useState(false);
    const confirmApprovalRef = useRef(false);
    const approveButton = useRef(null);
    const cancelApprovalButton = useRef(null);
    confirmApprovalRef.current = confirmApproval;
    useEffect(() => {
        if (confirmApproval) cancelApprovalButton.current?.focus();
        else approveButton.current?.focus();
    }, [confirmApproval]);
    const [reason, setReason] = useState('');
    const [problem, setProblem] = useState('');
    const panel = useRef(null);
    const closeButton = useRef(null);
    const busyRef = useRef(false);
    useEffect(() => {
        const previous = document.activeElement;
        closeButton.current?.focus();
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        function keyDown(event) {
            if (event.key === 'Escape' && !busyRef.current) {
                if (confirmApprovalRef.current) setConfirmApproval(false);
                else onClose();
            }
            if (event.key === 'Tab') {
                const elements = panel.current?.querySelectorAll('button:not(:disabled), a[href], textarea:not(:disabled)');
                if (!elements?.length) return;
                const first = elements[0], last = elements[elements.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        }
        window.addEventListener('keydown', keyDown);
        return () => { document.body.style.overflow = overflow; window.removeEventListener('keydown', keyDown); previous?.focus(); };
    }, []);
    async function review(action) {
        if (busyRef.current) return;
        if (action === 'approve' && !confirmApprovalRef.current) return;
        if (action === 'reject' && !reason.trim()) { setProblem('Enter a reason before rejecting this registration.'); return; }
        busyRef.current = true;
        setBusy(true); setProblem('');
        try {
            await api(`/admin/residents/${resident.id}/${action}-registration`, { method: 'POST', body: JSON.stringify(action === 'reject' ? { reason: reason.trim() } : {}) });
            onReviewed(action === 'approve' ? 'Registration approved. The resident can now sign in.' : 'Registration rejected. The account has been permanently deleted.');
        } catch (error) { setProblem(error.message); }
        finally { busyRef.current = false; setBusy(false); }
    }
    const fields = [['First name', resident.first_name], ['Last name', resident.last_name], ['Full name', resident.name], ['Email', resident.email], ['Contact number', resident.phone], ['Barangay', resident.barangay], ['Sitio', resident.sitio], ['Address', resident.address], ['Registration', resident.verification_status === 'verified' ? 'Approved' : resident.verification_status === 'rejected' ? 'Rejected' : 'Pending'], ['Account status', resident.account_status], ['Registered on', resident.created_at ? new Date(resident.created_at).toLocaleString() : null]];
    return <div className="admin-resident-backdrop" onMouseDown={event => { if (event.target === event.currentTarget && !busy) onClose(); }}>
        <section ref={panel} className="modal-panel admin-resident-modal" role="dialog" aria-modal="true" aria-labelledby="registration-title" aria-busy={busy}>
            <header className="admin-resident-header"><div><p className="eyebrow">REGISTRATION REVIEW</p><h2 id="registration-title">{resident.name}</h2><p>Review the resident's submitted information and National ID.</p></div><button ref={closeButton} type="button" className="admin-resident-close" aria-label="Close registration" disabled={busy} onClick={onClose}>×</button></header>
            <dl className="admin-resident-fields registration-details">{fields.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || 'Not provided'}</dd></div>)}</dl>
            <section aria-labelledby="registration-id-title"><h3 id="registration-id-title">National ID</h3><div className="registration-id-grid">{['front', 'back'].map(side => <RegistrationId key={side} residentId={resident.id} side={side} />)}</div></section>
            {problem && <div role="alert"><Notice kind="error">{problem}</Notice></div>}
            {resident.verification_status === 'pending' ? <section className="admin-resident-management"><h3>Review decision</h3><p>Approve after checking the registration details and both ID images. Rejecting permanently deletes this account and its uploaded ID images. The user will need to register again.</p><Field label="Reason for rejection"><textarea value={reason} onChange={event => setReason(event.target.value)} maxLength={1000} rows={3} disabled={busy} placeholder="Required when rejecting a registration" /></Field>{confirmApproval ? <div className="registration-approval-check" role="alertdialog" aria-labelledby="approval-check-title" aria-describedby="approval-check-message"><h3 id="approval-check-title">Double-check before approving</h3><p id="approval-check-message">Please double-check {resident.name}'s registration details and both National ID images. Make sure the information is correct and matches the ID. Confirming will approve the account and allow this resident to sign in.</p><div className="admin-resident-actions"><button ref={cancelApprovalButton} type="button" className="button button-outline" disabled={busy} onClick={() => setConfirmApproval(false)}>Cancel and review</button><button type="button" className="button button-primary" disabled={busy} onClick={() => review('approve')}>{busy ? 'Saving…' : 'Confirm approval'}</button></div></div> : <div className="admin-resident-actions"><button ref={approveButton} type="button" className="button button-primary" disabled={busy} onClick={() => { setProblem(''); setConfirmApproval(true); }}>Approve registration</button><Button type="button" kind="button-danger" disabled={busy} onClick={() => review('reject')}>Reject and delete account</Button></div>}</section> : <p>This registration has already been reviewed.</p>}
        </section>
    </div>;
}

function RegistrationId({ residentId, side }) {
    const [failed, setFailed] = useState(false);
    const url = `/api/admin/residents/${residentId}/national-id?side=${side}`;
    return <figure className="registration-id"><figcaption>{side === 'front' ? 'Front of National ID' : 'Back of National ID'}</figcaption>{failed ? <p role="status">ID image unavailable or not uploaded.</p> : <><a href={url} target="_blank" rel="noopener noreferrer" aria-label={`Open ${side} of National ID at full size`}><img src={url} alt={`${side} of resident's National ID`} onError={() => setFailed(true)} /></a><a href={url} target="_blank" rel="noopener noreferrer">Open full-size image</a></>}</figure>;
}

function AdminResidents() {
    const [search, setSearch] = useState('');
    const [submittedSearch, setSubmittedSearch] = useState('');
    const [verification, setVerification] = useState('');
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState(null);
    const [notice, setNotice] = useState('');
    const { data, error, loading, refresh } = useLoad('/admin/residents?search=' + encodeURIComponent(submittedSearch) + '&verification_status=' + verification + '&page=' + page);
    function trackResident(event) { event.preventDefault(); setSubmittedSearch(search.trim()); setPage(1); }
    function reviewed(message) { setSelected(null); setNotice(message); setPage(1); refresh(); }
    return <><div className="section-heading"><h2>Manage residents</h2></div>
        {notice && <div role="status"><Notice>{notice}</Notice></div>}
        <form className="toolbar" onSubmit={trackResident}><Field label="Track resident"><input value={search} onChange={e => setSearch(e.target.value)} placeholder="Resident name or email" /></Field><Button type="submit" kind="button-primary">Search</Button><Field label="All registrations"><select value={verification} onChange={e => { setVerification(e.target.value); setPage(1); }}><option value="">All registrations</option><option value="pending">Pending</option><option value="verified">Approved</option></select></Field></form>
        {error && <Notice kind="error">{error}</Notice>}{loading ? <Loading /> : <div className="table-wrap"><table><thead><tr><th>RESIDENT</th><th>EMAIL</th><th>ADDRESS</th><th>REGISTRATION</th></tr></thead><tbody>{(data?.data ?? []).map(resident => <tr key={resident.id}><td><button className="table-link" onClick={() => { setNotice(''); setSelected(resident); }}>{resident.name}</button></td><td>{resident.email}</td><td>{resident.address}</td><td>{resident.verification_status === 'verified' ? 'Approved' : resident.verification_status === 'rejected' ? 'Rejected' : 'Pending'}</td></tr>)}</tbody></table>{!data?.data?.length && <Empty>{submittedSearch ? 'Invalid: resident not found.' : 'No registrations found.'}</Empty>}</div>}
        <Pagination data={data} page={page} onChange={setPage} />
        {selected && <ResidentRegistration key={selected.id} resident={selected} onClose={() => setSelected(null)} onReviewed={reviewed} />}
    </>;
}
function WordTemplateUpload({ document, onUploaded }) {
    const [busy, setBusy] = useState(false);
    const [hasFile, setHasFile] = useState(false);
    const [problem, setProblem] = useState('');
    const fileInput = useRef(null);
    const latest = [...(document.templates ?? [])].sort((a, b) => b.id - a.id)[0];
    async function upload(event) {
        event.preventDefault();
        if (busy || !fileInput.current?.files?.length) return;
        const body = new FormData();
        body.append('template', fileInput.current.files[0]);
        setBusy(true); setProblem('');
        try { await api(`/admin/documents/${document.id}/template`, { method: 'POST', body }); fileInput.current.value = ''; setHasFile(false); onUploaded(); }
        catch (error) { setProblem(error.message); }
        finally { setBusy(false); }
    }
    return <form className="word-template-upload" onSubmit={upload}><p>{latest ? `Current template: ${latest.name}` : 'No Word template uploaded.'}</p><Field label="Word template (.docx)"><input ref={fileInput} type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required disabled={busy} onChange={event => setHasFile(!!event.target.files?.length)} /></Field><Button type="submit" kind="button-primary" disabled={busy || !hasFile}>{busy ? 'Uploading…' : latest ? 'Replace and publish template' : 'Upload and publish template'}</Button>{problem && <div role="alert"><Notice kind="error">{problem}</Notice></div>}</form>;
}
function AdminDocuments() {
    const confirm = useConfirm();
    const { data, error, refresh } = useLoad('/admin/documents'); const [name, setName] = useState(''); const [description, setDescription] = useState(''); const [purpose, setPurpose] = useState(''); const [fee, setFee] = useState(''); const [requirements, setRequirements] = useState(''); const [problem, setProblem] = useState(''); const [notice, setNotice] = useState(''); const [editing, setEditing] = useState(null); const [adding, setAdding] = useState(false);
    async function saveDocument(event) { event.preventDefault(); setProblem(''); setNotice(''); const payload = { name, description, purpose, fee, requirements: requirements.split('\n').map((item) => item.trim()).filter(Boolean), is_active: editing?.is_active ?? true }; try { await api(editing ? `/admin/documents/${editing.id}` : '/admin/documents', { method: editing ? 'PATCH' : 'POST', body: JSON.stringify(payload) }); setNotice(editing ? 'Document updated.' : 'Document added.'); setName(''); setDescription(''); setPurpose(''); setFee(''); setRequirements(''); setEditing(null); setAdding(false); refresh(); } catch (e) { setProblem(e.message); } }
    function editDocument(document) { setEditing(document); }
    async function toggle(document) { if (!await confirm({ title: 'Deactivate document?', message: 'Are you sure you want to deactivate this document?', confirmLabel: 'Yes', cancelLabel: 'No', danger: true })) return; try { await api(`/admin/documents/${document.id}`, { method: 'PATCH', body: JSON.stringify({ is_active: false }) }); setNotice('Document deactivated successfully.'); refresh(); } catch (e) { setProblem(e.message); } }
    async function remove(document) { if (!await confirm({ title: 'Delete document?', message: `Delete ${document.name}? This cannot be undone.`, confirmLabel: 'Yes', cancelLabel: 'No', danger: true })) return; try { await api(`/admin/documents/${document.id}`, { method: 'DELETE' }); setNotice('Document deleted successfully.'); refresh(); } catch (e) { setProblem(e.message); } }
    return <><div className="section-heading"><div><p className="eyebrow">SERVICE CATALOG</p><h2>Documents</h2></div><Button type="button" kind="button-primary" onClick={() => setAdding(true)}>+ Add document</Button></div>{error && <Notice kind="error">{error}</Notice>}{problem && !adding && <FlowMessage title="Invalid" message={problem} onClose={() => setProblem('')} />}{notice && <FlowMessage title="Success" message={notice} onClose={() => setNotice('')} />}<div className="admin-document-layout catalog-only-layout"><div className="catalog-list">{(data ?? []).map((document) => <article className="catalog-row" key={document.id}><div><b>{document.name}</b><p>{document.description}</p><small>₱{Number(document.fee).toFixed(2)} · {(document.requirements ?? []).length} requirements · {document.requests?.length ?? 0} requests</small><WordTemplateUpload document={document} onUploaded={() => { setNotice('Word template published to the resident catalog.'); refresh(); }} /></div><div className="catalog-actions" role="group" aria-label={`${document.name} actions`}><Status>{document.is_active ? 'Active' : 'Inactive'}</Status><button type="button" className="catalog-action catalog-action-edit" onClick={() => editDocument(document)}>Edit template</button>{document.is_active && <button type="button" className="catalog-action catalog-action-deactivate" onClick={() => toggle(document)}>Deactivate</button>}<button type="button" className="catalog-action catalog-action-delete" onClick={() => remove(document)}>Delete</button></div></article>)}</div></div>{editing && <DocumentEditor documentId={editing.id} close={() => { setEditing(null); refresh(); }} />}{adding && <div className="admin-resident-backdrop"><section className="modal-panel admin-resident-modal" role="dialog" aria-modal="true" aria-labelledby="add-document-title"><header className="admin-resident-header"><h2 id="add-document-title">Add document</h2><button type="button" className="admin-resident-close" aria-label="Close add document" onClick={() => setAdding(false)}>×</button></header><form className="settings-section form-stack" noValidate onSubmit={saveDocument}><p className="eyebrow">NEW DOCUMENT</p><h3>Document details</h3>{problem && <Notice kind="error">{problem}</Notice>}<Field label="Document name"><input value={name} onChange={(e) => setName(e.target.value)} required /></Field><Field label="Description"><textarea value={description} onChange={(e) => setDescription(e.target.value)} rows="2" required /></Field><Field label="Purpose"><textarea value={purpose} onChange={(e) => setPurpose(e.target.value)} rows="2" required /></Field><Field label="Fee · PHP"><input type="number" step="0.01" min="0" value={fee} onChange={(e) => setFee(e.target.value)} required /></Field><Field label="Requirements · one per line"><textarea value={requirements} onChange={(e) => setRequirements(e.target.value)} rows="3" required /></Field><Button type="submit" kind="button-primary">Add document</Button><Button type="button" kind="button-outline" onClick={() => setAdding(false)}>Cancel</Button></form></section></div>}</>;
}

function Pagination({ data, page, onChange }) {
    if (!data || data.last_page <= 1) return null;
    return <div className="action-row"><Button kind="button-outline" disabled={page <= 1} onClick={() => onChange(page - 1)}>Previous</Button><span>Page {page} of {data.last_page}</span><Button kind="button-outline" disabled={page >= data.last_page} onClick={() => onChange(page + 1)}>Next</Button></div>;
}

function AdminNotifications() {
    const [page, setPage] = useState(1);
    const [residentPage, setResidentPage] = useState(1);
    const [search, setSearch] = useState('');
    const [recipientType, setRecipientType] = useState('all');
    const [selected, setSelected] = useState({});
    const [notice, setNotice] = useState('');
    const [problem, setProblem] = useState('');
    const [busy, setBusy] = useState(false);
    const [composing, setComposing] = useState(false);
    const { data, error, refresh } = useLoad(`/admin/notifications?page=${page}`);
    const { data: residents, error: residentsError } = useLoad(`/admin/residents?search=${encodeURIComponent(search)}&page=${residentPage}`);
    function choose(resident, checked) {
        setSelected(current => {
            if (recipientType === 'individual') return checked ? { [resident.id]: resident.name } : {};
            const next = { ...current }; if (checked) next[resident.id] = resident.name; else delete next[resident.id]; return next;
        });
    }
    async function send(event) {
        event.preventDefault();
        if (busy) return;
        setProblem(''); setNotice('');
        const element = event.currentTarget;
        const form = new FormData(element);
        if (!form.getAll('documents[]').some(file => file instanceof File && file.size > 0)) form.delete('documents[]');
        const ids = Object.keys(selected);
        if (recipientType !== 'all' && (!ids.length || (recipientType === 'individual' && ids.length !== 1))) { setProblem('Choose the notification recipient(s).'); return; }
        form.set('recipient_type', recipientType);
        if (recipientType !== 'all') ids.forEach(id => form.append('resident_ids[]', id));
        setBusy(true);
        try {
            const result = await api('/admin/notifications', { method: 'POST', body: form });
            setNotice(`Notification sent to ${result.sent_to} resident(s).`);
            element.reset(); setSelected({}); setRecipientType('all'); setComposing(false); setPage(1); refresh();
        } catch (e) { setProblem(e.message); } finally { setBusy(false); }
    }
    return <section className="notifications-inbox admin-notifications-page"><div className="social-notifications-heading"><div><h2>Notifications</h2><p>Announcements and updates sent to your residents.</p></div><Button type="button" kind="button-primary" onClick={() => setComposing(true)}>+ Create notification</Button></div>{notice && <FlowMessage title="Notification sent" message={notice} onClose={() => setNotice('')} />}{problem && !composing && <FlowMessage title="Invalid" message={problem} onClose={() => setProblem('')} />}{(error || residentsError) && <Notice kind="error">{error || residentsError}</Notice>}
        <ResidentNotifications title="For you" subtitle="Registration alerts and system updates for your admin account." />
        {composing && <div className="admin-resident-backdrop"><section className="modal-panel admin-resident-modal notification-compose" role="dialog" aria-modal="true" aria-labelledby="compose-title"><header className="admin-resident-header"><div><p className="eyebrow">ANNOUNCEMENT</p><h2 id="compose-title">Create notification</h2></div><button type="button" className="admin-resident-close" aria-label="Close notification form" disabled={busy} onClick={() => setComposing(false)}>×</button></header><form className="form-stack" noValidate onSubmit={send}>
            {problem && <Notice kind="error">{problem}</Notice>}
            <Field label="Title"><input name="title" maxLength="150" required /></Field>
            <Field label="Message"><textarea name="message" rows="4" maxLength="3000" required /></Field>
            <Field label="Recipient group"><select value={recipientType} onChange={e => { setRecipientType(e.target.value); setSelected({}); }}><option value="all">All residents</option><option value="individual">Specific resident</option><option value="selected">Custom group</option></select></Field>
            {recipientType !== 'all' && <><Field label="Find residents"><input value={search} onChange={e => { setSearch(e.target.value); setResidentPage(1); }} placeholder="Name or email" /></Field>
                <div className="notification-list">{(residents?.data ?? []).map(resident => <label className="toggle-row" key={resident.id}><span>{resident.name}<small> · {resident.email}</small></span><input type={recipientType === 'individual' ? 'radio' : 'checkbox'} name="recipient_selection" checked={Boolean(selected[resident.id])} onChange={e => choose(resident, e.target.checked)} /></label>)}</div>
                <Pagination data={residents} page={residentPage} onChange={setResidentPage} /><p>{Object.values(selected).join(', ') || 'No residents selected.'}</p>
            </>}
            <Field label="Priority"><select name="priority"><option value="normal">Normal</option><option value="important">Important</option></select></Field>
            <Field label="Add documents" hint="Up to 5 PDF, Word or image files, 10 MB each."><input type="file" name="documents[]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" multiple /></Field>
            <Button type="submit" kind="button-primary" disabled={busy}>{busy ? 'Sending…' : 'Send notification'}</Button>
        </form></section></div>}<div className="notification-history-header"><h3>Recent activity</h3><span>Newest first</span></div><NotificationFeed items={data?.data ?? []} />{!data?.data?.length && <div className="social-note-empty"><Icon name="bell" /><h3>No notifications sent yet</h3><p>Create a notification to share an update with your residents.</p></div>}<Pagination data={data} page={page} onChange={setPage} /></section>;
}

function AdminReports() {
    return <><div className="section-heading"><h2>Reports</h2></div><AdminRequestReports /></>;
}

function AdminRequestReports() {
    const [page, setPage] = useState(1);
    const [selectedId, setSelectedId] = useState(null);
    const [problem, setProblem] = useState('');
    const [busy, setBusy] = useState(false);
    const { data, error, refresh } = useLoad(`/admin/reports?page=${page}`);
    async function markAllRead() {
        setBusy(true);
        try { await api('/admin/reports/read-all', { method: 'PATCH' }); refresh(); }
        catch (e) { setProblem(e.message); } finally { setBusy(false); }
    }
    return <section className="section-block"><div className="section-heading"><h3>Submitted requests</h3><Button kind="button-outline" onClick={markAllRead} disabled={busy}>Mark all as read</Button></div>
        {(error || problem) && <Notice kind="error">{error || problem}</Notice>}
        <div className="table-wrap"><table><thead><tr><th>REQUEST</th><th>RESIDENT</th><th>DOCUMENT</th><th>STATUS</th><th>READ</th></tr></thead><tbody>{(data?.data ?? []).map(record => <tr className={record.admin_read_at ? 'admin-report-read' : ''} key={record.id}><td><button className="table-link" onClick={() => setSelectedId(record.id)}>{record.tracking_number}</button></td><td>{record.resident?.name}</td><td>{record.document_snapshot?.name}</td><td><Status>{record.status}</Status></td><td>{record.admin_read_at ? 'Read' : 'Unread'}</td></tr>)}</tbody></table></div>
        {!data?.data?.length && <Empty>No submitted requests.</Empty>}<Pagination data={data} page={page} onChange={setPage} />
        {selectedId && <AdminReportContent id={selectedId} close={() => { setSelectedId(null); refresh(); }} />}
    </section>;
}

function AdminReportContent({ id, close }) {
    const { data, error } = useLoad('/admin/reports/' + id);
    return <FlowMessage className="document-content-dialog" title="Document content" message={error || (data ? <><span className="table-sub">{data.document_snapshot?.name}</span><span className="table-sub">{data.resident_snapshot?.name}</span><span className="table-sub">{data.purpose}</span><span className="table-sub">{data.status}</span></> : 'Loading…')} onClose={close} />;
}

function AdminSettings() {
    return <><div className="section-heading"><h2>Admin settings</h2></div><ResidentSettings /></>;
}

createRoot(document.getElementById('app')).render(<><App /><DialogAccessibility /></>);
