# eBaryo · Barangay Document Request System

Laravel 12, React 19, and Vite application for resident document requests and a single-admin workflow. The supplied flowcharts are included in `docs/flowcharts/`.

## Laragon setup

1. Extract this project into `C:\laragon\www\ebaryo` and open that folder in a terminal.
2. Copy `.env.example` to `.env`. Set `ADMIN_NAME`, `ADMIN_EMAIL`, and a strong `ADMIN_PASSWORD`, plus `BARANGAY_NAME` and `BARANGAY_ADDRESS`.
3. Run `composer install`, `php artisan key:generate`, `npm install`, and `npm run build`.
4. Create a MySQL database named `ebaryo` in Laragon, then set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=ebaryo`, `DB_USERNAME=root`, and your local database password in `.env`. The default SQLite settings are also usable for a quick local run.
5. Run `php artisan migrate --seed`. This creates the single configured admin and sample document types. No default admin password is shipped.
6. Start the site with `php artisan serve` or use Laragon Auto Virtual Hosts. Run `php artisan schedule:work` in a second terminal for pickup reminders and three-day expiry.

## Registration review and email

The landing page offers Login and Register. Registration collects first name, last name, email, contact number, barangay, sitio, National ID front and back (JPG/PNG), password, and password confirmation, in that order. Both ID images are stored privately for admin review. New accounts remain pending until the Barangay Admin approves or rejects the registration in Admin > Residents. Login displays an Invalid dialog with OK to retry, or Login Success with OK to continue to the appropriate dashboard.

Set `document_number_format` in Admin Settings to a format such as `BRGY-{year}-{id}`. Supported tokens are `{year}` and `{id}` (six-digit padded request ID).

Login and registration include Back and Forgot password links. Password recovery emails a confirmation link, valid for 10 minutes and one reset. Opening the link displays the new-password form; the password changes only when the form is submitted. Resending invalidates the previous link; requests and verification attempts are limited. Successful registration returns to the landing page.

For real confirmation email delivery, configure `.env` with `MAIL_MAILER=smtp`, your provider's `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME` (`smtps` for implicit TLS, or `smtp` for STARTTLS), and a verified `MAIL_FROM_ADDRESS`. Run `php artisan config:clear` after changing settings. The default `log` mailer writes email to the application log and does not deliver to inboxes. Confirmation mail is sent immediately and does not require a queue worker. Password reset does not change an account's approval status.

Run `php artisan mail:check` to check SMTP connectivity and authentication without sending mail. Then request a confirmation link using a registered account to verify inbox delivery. Password recovery returns an unavailable message for log/array mailers and failover mailers, which cannot confirm real delivery. Store SMTP credentials only in your local `.env`.

For personal Gmail senders, run `php artisan mail:setup-gmail` in your terminal. Enter the sender Gmail address and a Google App Password when prompted; the password is hidden. Create the App Password at https://myaccount.google.com/apppasswords after enabling 2-Step Verification. Setup checks SMTP authentication before saving `.env`, sets the sender address, and clears cached configuration. It does not send mail or require barangay approval. A failed connection leaves `.env` unchanged.

On Windows, this local installation uses the exported Windows trusted public root certificates in `storage/app/certificates/windows-roots.pem` for SMTP, when that file exists. This handles PHP installations whose CA bundle does not include the certificates trusted by Windows. Peer and hostname verification remain enabled. Set `MAIL_SMTP_CA_FILE` to use another trusted PEM bundle. Gmail setup reports certificate failures separately from rejected credentials; after a failed setup, run it again because credentials are not saved on failure.

## Implemented workflows

- Resident registration with private National ID uploads and manual admin approval, account login, profile and settings.
- Resident dashboard totals and unread notifications, document search by name, purpose and additional information, editable request summary, submission, request history/timeline, corrections, and Under Review cancellation.
- Profile picture, editable full name/email/contact number, validated password changes, saved email/in-app preferences, notification reading, and confirmed logout to the landing page.
- Admin request search/review, approval/rejection/correction, processing, pickup, release, printable template-based documents, cash payment records, residents, catalog/templates, announcements, settings, and reports.
- Cash payment is informational and does not block release. Residents see only Paid/Unpaid. Pickup reminder and expiry are scheduled from the three-day deadline.

Run checks with `php artisan test` and `npm run build`. The ZIP excludes `vendor/`, `node_modules/`, and local `.env`; install dependencies after extracting.

---

## Framework

This project uses the Laravel framework and its standard Composer/Vite tooling.


<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
