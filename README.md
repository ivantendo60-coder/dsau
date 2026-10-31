# DSAU Admissions Portal (PHP + MySQL)

Online admission form + admin dashboard for **Digital Shift Academy Uganda**.

- **Public admission form** (`index.php`): same questions as the DSAU Google Form (full name, entry requirements grid, fee acknowledgements, gender, date of birth, phone/WhatsApp, email, location, education, employment, how you heard about us), plus a course and level picker with a live fee preview.
- **Admin dashboard** (`admin/`): side navigation, responsive on phones/tablets, KPI cards and 6 charts (applications by course and status, status overview, admission rate by course, payments collected vs outstanding by course, 30-day trend, referral sources). Choose DSAU, Ocean, Forest, Violet, Dark, Blue, Orange, Golden, Blue + Orange, or Blue + Orange on white from the theme selector; your choice is saved in the browser.
- **Applications**: search + filters (intake, status, level, payment, dates), bulk status update, CSV export, detail page with admission decision, internal notes, WhatsApp/email shortcuts. The admin course selector filters dashboard metrics, applications/admissions, exports, and payments across the portal.
- **Payments**: record installments per applicant (Mobile Money / Bank / Cash), automatic Unpaid / Partial / Paid status and balances.
- **Courses**: add, edit, reorder, activate/deactivate, delete (only when no applications). Active courses appear on the form automatically.
- **Intakes**: open/close intakes with deadlines (the form closes itself after the deadline).
- **Settings**: change password, add staff/admin users.

## Requirements
PHP 8.0+ (with `pdo_mysql`) and MySQL / MariaDB. XAMPP, WAMP, Laragon or MAMP all work.
Charts load Chart.js and the Inter font from a CDN, so the browser needs internet access.

## Install (XAMPP example)
1. Copy the `dsau-portal` folder to `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in XAMPP.
3. Import the database: open phpMyAdmin -> **Import** -> choose `database.sql` -> Go.
   (or `mysql -u root < database.sql`)
4. Open `config.php` and check the `db` settings (XAMPP default: user `root`, empty password) and `base_url` (`/dsau-portal`).
5. Visit `http://localhost/dsau-portal/admin/setup.php` and create the first administrator.
   The setup page disables itself once an admin exists.
6. Sign in at `http://localhost/dsau-portal/admin/login.php`.
   The public form is at `http://localhost/dsau-portal/`.

## Admin password recovery
Use **Forgot your password?** on the admin sign-in page. A single-use reset link is emailed to the account's registered address and expires after 60 minutes. Configure SMTP under `mail` in `config.php` and set `app_url` to the site's public origin (for example, `https://example.com`) so the link points to the right site.

For an existing installation, import `migrations/001_password_reset_tokens.sql` into the portal database before using password recovery. Fresh installations get this table from `database.sql`.

## Applicant email notifications
Install the Composer dependencies with `composer install`, then configure the `mail` section in `config.php` with your SMTP host, port, encryption (`tls`, `ssl`, or `none`), username, password, and sender address. Use the credentials and app-password requirements from your email provider; do not commit real credentials to source control.

The portal emails applicants after a successful submission, whenever their application status changes, and after a payment is recorded/removed or the tuition fee is adjusted. When an application is changed to **Admitted**, PHPMailer automatically sends a course-specific admission email with the course, level, intake, application reference, and admissions contact. This works for individual and bulk status updates. Payment emails include the recalculated status, total paid, and remaining balance. Internal admin notes are never included. Email delivery errors are written to the PHP error log and do not undo saved applications or payments.

Using PHP's built-in server instead:
```
cd dsau-portal
# set 'base_url' => '' in config.php
php -S localhost:8000
```

## Preview with demo data (optional)
```
php tools/seed_demo.php          # adds 60 fake applications and payments
php tools/seed_demo.php --clear  # removes only the demo rows
```

## Branding
- Colours are CSS variables at the top of `assets/css/app.css` (`--primary`, `--accent`, `--navy-*`). Replace them with the exact hex codes from the DSAU logo/website and the whole portal follows.
- Logos: by default they load from `https://digitalshiftacademyug.com/logo.png` and `logow.png`. To use local copies, save them as `assets/img/logo.png` and `assets/img/logow.png`; local files win automatically.
- Name, phone numbers, email, WhatsApp number and fees text are in `config.php`.

## Notes on fees
Each course has a **Beginner** and **Advanced** fee (defaults UGX 300,000 and 400,000, as on the website/form). The fee is copied onto the application when it is submitted, so changing a course fee later does not alter existing applications. An admin can adjust one applicant's fee (e.g. discount) on the application page.

## Security notes
- Prepared statements everywhere, CSRF tokens on every form, password hashing, login throttling (5 failures / 15 min per IP), honeypot + per-IP submission limit on the public form, CSV formula-injection protection.
- Before going live: use HTTPS, set a strong DB password, and don't expose `database.sql` / `tools/` (delete `tools/` on the server).
