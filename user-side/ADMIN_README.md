# Barangay Document Request System — admin side

This admin implementation extends the standalone PHP/PDO resident app in this folder. It shares its database configuration, native PHP session, Login page, and five existing tables. The separate Laravel application in the parent directory is unchanged. PHP 8.2+, MySQL 8, PDO MySQL, DOM, mbstring and fileinfo are required. No Composer, Node, CDN or JavaScript framework is needed to run the app.

## Laragon setup

1. Start Apache and MySQL. Check `config/database.php` and the existing `BARANGAY_DB_*` environment settings. Both portals use the same connection.
2. For a new database, run `php database/install.php` first. For an existing user-side database, keep its data and skip this step.
3. Back up the database, then run `php database/migrate-admin.php` once from this folder. Alternatively select the existing database in HeidiSQL/phpMyAdmin and execute `database/admin.sql` once. The SQL includes every new column, table and foreign key. MySQL DDL commits independently; if a migration fails partway through, compare the schema against `admin.sql` and complete the remaining statements before using the app. The CLI checks for the `role` column to prevent blindly applying the migration twice; that check is not a full schema verification.
4. Register the intended administrator through the shared registration page. Inspect that account's uploaded IDs locally and set its status to `Verified` using your database administrator. Then run:

   ```powershell
   php database/create-admin.php administrator@example.com
   ```

   Supply the actual existing account email. This local-only command grants the `admin` role to that verified account and records the provisioning action. It does not set or disclose passwords. Public registration always creates residents; there is no public role selector or default admin password.

5. Open `http://localhost/New%20folder/user-side/index.php?page=login`. Sign in with the administrator's registered password. The same Login page redirects admins directly to `admin/index.php`; residents keep their original dashboard.
6. Admin entry point: `http://localhost/New%20folder/user-side/admin/`. Every admin entry point checks the database-backed session, `role = admin`, and verified account status. Enable Apache `.htaccess` overrides. The supplied restrictions keep uploads, configuration, include files and page partials private.

For the PHP development server, run from this folder:

```powershell
php -d upload_max_filesize=6M -d post_max_size=14M -S 127.0.0.1:8081 router.php
```

Open `http://127.0.0.1:8081/index.php?page=login`. Keep the supplied router: it permits only public entry points and assets. Use the same PHP upload limits under Apache. Configure PHP's timezone for the barangay, normally `Asia/Manila`, and align the MySQL server timezone. Schedule dates entered in the admin form are local date/time values.

## Admin flows

- **Dashboard:** pending registrations, total requests, awaiting review, ready for pickup and released counts; all requested navigation; latest ten admin audit entries. Logout asks the specified question, No returns to Dashboard, and Yes ends the session and shows the success message on Login.
- **Document Request:** all requests, revealable name/ID search, database document filter, and all eleven requested status choices. Existing resident requests with `Pending` status appear as `Submitted` in admin views and match the Submitted filter. New status updates persist the selected workflow status. Manage opens review, reason, payment, processing/pickup dates, Processing/Cancel, pickup and delivery fields. Review, status and document saves each create a resident notification and audit record in the same transaction.
- **Document editor:** Continue and edit loads saved content, or starts a draft populated from the request snapshot and resident data. The editor supports headings, bold, italic, underline, lists, undo and redo. Save Draft persists formatted content. Save & Print persists first, shows `Saved Successfully`, and offers the browser's print dialog after OK. Print CSS prints only the document on A4. Optimistic version checks reject stale drafts. The editor requires JavaScript. Stored formatting uses a server-side tag whitelist with no active attributes, scripts, remote assets or embedded content. This is an HTML document editor and browser printing workflow; it does not import/export Word `.docx` files.
- **Residents:** name/email search, All Registrations/Pending/Approved/Rejected filters, private front/back National ID viewing, and approval/rejection of pending registrations. Approval is displayed as `Approved` and saved as the existing `Verified` value so resident login remains compatible. Each ID view is audited. Accounts in a non-verified state cannot sign in.
- **Documents:** all catalog records, Add/Edit with required name, description, purpose, fee, requirements and processing time; exact deactivate/delete confirmations and messages; Activate restores inactive documents. Inactive documents are hidden from resident requests. Deleting a catalog entry sets historical requests' `document_id` to NULL; request snapshots, content and notifications remain intact.
- **Notification:** database priorities; All, Specific or Custom recipients; searchable resident picker for Specific and Custom; optional PDF/JPG/PNG/DOCX attachment up to 5 MB. Announcement metadata is saved once; each selected resident gets a notifications row. Email is queued according to preferences. Recent announcements show their priority, recipient count and attachment; the latest 100 deliveries are listed below. Recipients can download their announcements' attachments through `attachment.php`; other residents receive 404. Admins may download all announcement attachments. Upload files are never served directly.
- **Reports:** request history with per-admin read state; opening a document uses POST to mark it read and reveals request details and saved content, with OK to return. Mark All as Read affects the displayed request IDs. Read rows are grey.
- **Settings:** current-password authentication, matching new password validation, `password_hash()` storage, and saved Email Updates/In-app Notifications switches. Passwords are 8–72 bytes. Resident in-app preference controls notification visibility and unread counts; records remain stored for delivery history.

## Email delivery

The app commits notifications and email outbox entries with the underlying action. Configure a working PHP mail transport before enabling scheduled email delivery. On Laragon this typically means configuring PHP's SMTP/sendmail settings and restarting Apache; use the same PHP configuration for the CLI worker.

Set `BARANGAY_MAIL_FROM` to a valid sender email, then schedule this command in Windows Task Scheduler, with this folder as the working directory:

```powershell
php scripts/send-email.php
```

Each execution processes at most 100 unsent entries and retries failed entries up to five attempts. A MySQL advisory lock prevents overlapping workers. The worker rechecks the resident's email preference. `email_outbox.sent_at` means the mail transport accepted the message; it does not establish inbox delivery. Inspect `last_error` and `attempts` for failures. No emails are sent during the test suites. Queued messages include the announcement text; attachments are downloaded through the authenticated portal.

## Files and SQL

```text
user-side/
  admin/
    index.php                 Explicit admin page router
    action.php                CSRF-protected POST operations
    media.php                 Authorized, audited National ID delivery
    includes/                 Access guard, helpers, sidebar and dialogs
    pages/                    Dashboard, requests, residents, documents,
                              notifications, reports, settings, editor
    assets/admin.css          Shared-theme responsive layout and print CSS
    assets/admin.js           Dialogs, validation, picker and editor controls
  config/database.php         Shared PDO configuration
  includes/                   Existing session, PDO, CSRF and resident helpers
  assets/                     Existing shared resident assets
  uploads/announcements/      Private announcement attachments (created on upload)
  attachment.php              Session- and recipient-protected downloads
  database/admin.sql          Complete one-time admin migration
  database/migrate-admin.php  CLI migration runner
  database/create-admin.php   CLI grant for an existing verified account
  scripts/send-email.php      CLI email queue worker
  tests/admin-smoke.mjs       Isolated HTTP/MySQL integration checks
  tests/admin-browser.mjs     Browser interactions and mobile layout checks
  tests/admin-db.php          Isolated database fixtures
```

`admin.sql` adds `users.role`, `documents.purpose`, request payment/date/fulfillment/reason/content/update fields, and `notifications.announcement_id`. It creates `notification_priorities`, `announcements`, `admin_request_reads`, `admin_audit_logs`, and `email_outbox`. Existing request and resident records are preserved. All data input uses prepared PDO statements. POST operations validate CSRF. Admin changes record the administrator, action, record ID, before/after values where applicable, and timestamp; password values are never logged.

## Verification

Run from this folder with MySQL available:

```powershell
node tests/admin-smoke.mjs
node tests/smoke.mjs
node tests/admin-browser.mjs
```

The suites create and drop uniquely named isolated databases and clean up their own uploaded files. Test accounts are fixtures only. The MySQL test user needs create/drop permission for these temporary databases. Browser checks use installed Microsoft Edge (override with `BROWSER_BINARY`) and save screenshots in `tests/runtime/`. They use a temporary headless browser with `--no-sandbox`, confined to tests. Normal operation needs none of these test permissions or browser flags.

The delivered code passed 52 admin HTTP/database checks, 50 existing resident checks, PHP syntax checks, and browser checks covering admin login, search, editor save/print, confirmations, recipient picker, validation, logout and eight mobile pages. The application database is not migrated automatically by a web request; run the setup steps above before using the admin portal.
