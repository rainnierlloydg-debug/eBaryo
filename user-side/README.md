# Barangay Document Request System — resident and admin portals

The PHP/PDO admin area is now included. See [ADMIN_README.md](ADMIN_README.md) for the required migration, first-admin setup, email worker, admin files and verification commands.

Complete standalone PHP 8.2+ application. Uses MySQL 8, PDO prepared statements, native PHP sessions, `password_hash()` and `password_verify()`. It has no Composer, Node or external CDN dependency. This folder is separate from the existing Laravel application.

## Run with Laragon

1. Start MySQL and Apache in Laragon.
2. Import [`database/schema.sql`](database/schema.sql) in HeidiSQL or phpMyAdmin. It creates `barangay_user_system`, all five tables, and three sample documents. Sample document fees, requirements and processing times are editable database data.
3. Check [`config/database.php`](config/database.php). Defaults are `127.0.0.1:3306`, database `barangay_user_system`, user `root`, empty password. Set your own database credentials there or use `BARANGAY_DB_HOST`, `BARANGAY_DB_PORT`, `BARANGAY_DB_NAME`, `BARANGAY_DB_USER`, `BARANGAY_DB_PASSWORD` environment variables.
4. In Laragon's PHP configuration, set `upload_max_filesize = 6M` and `post_max_size = 14M`, then restart Apache. The application enforces 5 MB per National ID image and 2 MB per profile picture.
5. Open `http://localhost/New%20folder/user-side/`. You can also configure a Laragon virtual host with this folder as its document root. Apache must permit `.htaccess` overrides.

Alternative development server, from this folder:

```powershell
php -d upload_max_filesize=6M -d post_max_size=14M -S 127.0.0.1:8081 router.php
```

Open `http://127.0.0.1:8081/`. Use the supplied router so upload and implementation directories remain inaccessible.

Instead of importing SQL manually, run `php database/install.php` from this folder after checking the database configuration. It creates missing tables and sample documents without deleting existing records.

## Verification handoff

Registration creates a **Pending** account and never signs it in automatically. After installing the admin migration, an administrator can inspect both ID images in Manage Residents and approve or reject the registration. Approval saves **Verified** to preserve resident login compatibility. For first-admin provisioning or local testing only, after checking the uploaded IDs, run this prepared statement in your administrative PHP code:

```php
$statement = $pdo->prepare('UPDATE users SET status = :status WHERE email = :email');
$statement->execute(['status' => 'Verified', 'email' => 'resident@example.com']);
```

Alternatively, update that specific account's `status` in HeidiSQL. Unverified accounts cannot sign in or access protected pages. ID paths are saved in `users.national_id_front` and `users.national_id_back`; their files are in `uploads/national-ids/` and are not publicly served. Existing Laravel tables and accounts are separate from this standalone database.

## Files

```text
user-side/
  index.php                 Explicit page router and session protection
  action.php                POST handlers and validation
  media.php                 Session-protected profile image delivery
  router.php                Optional PHP development-server router
  config/database.php       Database credentials
  includes/bootstrap.php    PDO, session and response setup
  includes/functions.php    Escaping, CSRF, validation and upload helpers
  includes/header.php       Responsive navigation and page shell
  includes/footer.php       Message and logout dialogs
  pages/                    Landing, registration, login, dashboard,
                            request, history, notifications, profile, settings
  assets/style.css          Responsive styles, no external assets
  assets/app.js             Dialogs, profile edit controls, duplicate-click guards
  database/schema.sql       MySQL schema and sample document records
  uploads/                  Private National ID and profile image files
```

## Implemented flow

- Landing contains Login and Register.
- Registration requires every listed field and both images, checks unique email and matching passwords, hashes the password, creates default preferences, and shows a success message. Passwords must be 8–72 bytes; contact numbers accept digits, a leading plus, spaces, parentheses and hyphens.
- Login failures show **Invalid** and OK. Verified login shows **Login Success**; OK opens the dashboard. Authentication uses a regenerated PHP session ID.
- Dashboard shows only total requests, Pending requests and unread notifications, plus the requested navigation.
- Active documents can be found by name or through **View and Request Document**. Review validates Purpose before Additional Information. Summary preserves the entered data for **Back and Edit**. Submission saves the reviewed document data with **Pending** status and creates its notification in one database transaction. The submission key prevents duplicate requests.
- History lists only the signed-in user's requests. Individual notifications are marked read when opened; **Mark All as Read** affects only that user and greys out their notifications.
- Profile displays the picture, name, email and contact number. Click a field's label to edit it. Full name is edited as first and last name. Invalid saves show **Invalid**; successful saves show **Save Success**.
- Settings validate the current password and new confirmation; success shows **New Password Saved!**. Preferences load and save both toggles with **Saved Preferences**. This user-side scope stores preferences; it does not add an outgoing email service or change the required submission-notification behavior.
- Logout No leaves the session active and returns to Dashboard. Yes destroys the authenticated session and shows **Logout Successful** on the Login page.

All dynamic values are HTML-escaped. User-owned records are selected with the session user ID, never a posted user ID. POST actions require CSRF tokens. National IDs cannot be fetched through the web server; only the current user's profile photo is exposed through `media.php`. Do not place an alternative web server in front of this folder without equivalent upload-directory restrictions.

## Verification

Run `node tests/smoke.mjs` from this folder with MySQL running and Node 22+ installed. The suite imports the supplied schema into a uniquely named test database, starts a temporary PHP server, checks the real HTTP forms and database results, then removes that test database and its uploads. The configured MySQL user needs permission to create/drop that isolated test database. Normal application operation does not need those permissions.

`node tests/browser.mjs` additionally checks the forms and dialogs in headless Microsoft Edge and saves desktop/mobile screenshots under `tests/runtime/`. Set `BROWSER_BINARY` if Edge is installed elsewhere. Its isolated test browser uses `--no-sandbox` for restricted local execution; this flag is confined to the test runner.
