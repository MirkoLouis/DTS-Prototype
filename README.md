# DepEd Iligan - Document Tracking System (DTS) Prototype

A modern, production-ready web application for digitizing, tracking, and managing official documents for the **DepEd Division of Iligan City**. It features role-based workflow routing, real-time analytics, physical QR code tracking, and a cryptographically secure audit trail powered by SHA-256 hash-chaining and Ed25519 digital signatures.

---

## ⚡ Architectural Evolution: The Shift to Pure Vanilla PHP

This repository contains two distinct implementations of the system, reflecting a deliberate architectural evolution:

```
DTS Prototype/
├── working-php/     <-- ACTIVE PRODUCTION CODEBASE (Pure Vanilla PHP 8.3)
└── backup-laravel/  <-- HISTORICAL REFERENCE CODEBASE (Original Laravel 12 Build)
```

Thesis manuscript working files kept at the repository root (`Manuscript Revisions (M1-M6).txt`, `Manuscript Revisions (M7-M20).md`, `Copy of WORKING.md`) are gitignored and never committed.

### Why Port to Pure Vanilla PHP 8.3?

1. **Framework-Free Performance:** Eliminates framework bootstrapping overhead, delivering near-instant page loads and sub-millisecond raw PDO database query execution.
2. **Zero-Dependency Core:** Runs natively using pure PHP 8.3 and MySQL 8.0 without heavy framework runtime dependencies.
3. **Transaction Safety & Optimistic Locking:** Centralizes database state mutations inside [DocumentWorkflowService.php](working-php/src/Modules/Documents/DocumentWorkflowService.php) using explicit `PDO` transactions with optimistic version checks; the conditional `UPDATE` takes an InnoDB row lock that serializes hash-chain appends per document, eliminating race conditions under concurrent loads.
4. **Container-Native Scheduling:** Replaced host `cron` daemons with a unified CLI worker ([console.php](working-php/console.php)) featuring an internal task scheduler loop for telemetry sampling, TAT backfilling, metrics rollups, response cache GC, and automated stale pending document garbage collection inside containerized environments (Distrobox / Docker).

---

## 📸 System Screenshots

### Guest Submission
<img src="working-php/documentation/screenshots/Guest%20Submission%20(Filled).png" width="100%" alt="Guest Submission" style="border: 1px solid black;">

### Guest Submission Success
<img src="working-php/documentation/screenshots/Guest%20Submission%20Success.png" width="100%" alt="Guest Submission Success" style="border: 1px solid black;">

### Document Intake
<img src="working-php/documentation/screenshots/Document%20Intake.png" width="100%" alt="Document Intake" style="border: 1px solid black;">

### Department Tasks
<img src="working-php/documentation/screenshots/Department%20Tasks.png" width="100%" alt="Department Tasks" style="border: 1px solid black;">

### Department Digital Signature
<img src="working-php/documentation/screenshots/Department%20Digital%20Signature.png" width="100%" alt="Department Digital Signature" style="border: 1px solid black;">

### Document Releasing
<img src="working-php/documentation/screenshots/Document%20Releasing.png" width="100%" alt="Document Releasing" style="border: 1px solid black;">

### Admin Dashboard
<img src="working-php/documentation/screenshots/Admin%20Dashboard.png" width="100%" alt="Admin Dashboard" style="border: 1px solid black;">

### Admin Dashboard (Dark Mode)
<img src="working-php/documentation/screenshots/Admin%20Dashboard%20(Dark%20Mode).png" width="100%" alt="Admin Dashboard (Dark Mode)" style="border: 1px solid black;">

### Admin System Overview
<img src="working-php/documentation/screenshots/Admin%20System%20Overview.png" width="100%" alt="Admin System Overview" style="border: 1px solid black;">

### [View More Screenshots ➔](working-php/documentation/screenshots/)

---

## 📄 Example Outputs & Generated Reports

Real examples of document tracking forms and administrative export reports are located in [working-php/documentation/exports](working-php/documentation/exports):

- **Sample Tracking Form (PDF):** [document-tracking-form-DEPED-E5345FDA4F.pdf](working-php/documentation/exports/document-tracking-form-DEPED-E5345FDA4F.pdf) — Generated PDF containing submitter information, document requirements, and a scannable QR code.
- **Sample Release Report (XLSX):** [released-documents-20260728_000645-677docs.xlsx](working-php/documentation/exports/released-documents-20260728_000645-677docs.xlsx) — Streaming Excel export of completed document throughput generated via `OpenSpout`.

---

## 📖 System Documentation & Manuals

Comprehensive user manuals are provided in [working-php/documentation/user_manuals](working-php/documentation/user_manuals):

- 📘 **[Administrator Manual](working-php/documentation/user_manuals/admin_manual.md):** Account management, database performance, integrity checks, backups, and snapshot Auto-Resolve.
- 📗 **[Records Officer Manual](working-php/documentation/user_manuals/officer_manual.md):** Document intake, route finalization, QR tracking form printing, camera scanning, and releasing.
- 📙 **[Department Staff Manual](working-php/documentation/user_manuals/staff_manual.md):** Departmental task processing, physical folder receipt, action remarks, and digital signatures.
- 🔐 **[Cryptographic Logs Explained](working-php/documentation/CRYPTOGRAPHIC_LOGS_EXPLAINED.md):** How hash-chain logs work (mechanics), why seals/signatures match administrative chain-of-custody needs, and cryptography vs encryption.
- 📖 **[Cryptographic Hash Verification Layman Guide](working-php/documentation/HASH_VERIFICATION_LAYMAN_GUIDE.md):** Simple, step-by-step breakdown of how document hashes, payload state hashes, digital signatures, and tamper detection work without complex jargon.
- 🎯 **[Value Proposition & Multi-Stakeholder Pitch](working-php/documentation/PITCH_AND_VALUE_PROPOSITION.md):** Architectural value propositions and technical pitches for IT Admins, Executives, Cryptographers, Office Staff, and Citizens.

---

## ♿ Accessibility (`working-php`)

The interface is built to be operated without a mouse and read by a screen reader:

- **Modal dialogs** (confirm, signing PIN, backups restore, Users reset, QR scanner, report progress, decline and the guest tracking dialogs) all use one shared module, `public/js/modal-dialog.js`, following the WAI-ARIA modal dialog pattern: they carry `role="dialog"`, `aria-modal="true"` and a label; focus moves into the dialog on open and back to the control that opened it on close; Tab and Shift+Tab stay inside (focus trap); the page behind is `inert`; Escape or a click outside closes the top dialog (stacked dialogs close one at a time). The mandatory Security PIN prompt is the exception: it cannot be dismissed, it keeps the focus and its Log Out button stays reachable, and a refused PIN is explained inside it (an alert linked to both fields). Dialogs that could not be dismissed by Escape or an outside click before and can now: report progress, integrity progress (the job keeps running; the Run button re-opens its progress), the guest track dialog, backups restore and Users reset. Toasts stay visible and live above an open dialog (they are not made inert); they can be dismissed with the pointer, and Tab stays inside the dialog.
- **Menus**: the user menu and the notification list are real buttons with `aria-expanded` and `aria-controls`, and close on Escape with the focus back on their button. The unread count is part of the bell's accessible name.
- **Names and labels**: icon-only buttons and links have an accessible name, decorative icons are hidden from assistive technology, every form control has a label, and failed fields point at their message (`aria-invalid`, `aria-describedby`). Forms carry `autocomplete` hints (login, guest form, password change, Security PIN).
- **Structure and announcements**: every page has exactly one `h1` (the header bar title, the system name on guest pages, the status title on error pages) and no skipped heading level. Toasts and flash messages are live regions (`role="alert"` for errors and warnings, `role="status"` for success and information); while a dialog is open they stay live, and a refused Security PIN is also shown inside the prompt.
- **Forms and password managers**: `autocomplete` is a hint, browsers and password managers decide. The signing PIN field uses `off` and the setup fields `new-password`, but a password manager may still offer to fill or save a Security PIN.
- **Focus** is always visible: no control removes its outline without a focus ring.

Checked by `composer test:accessibility-markup` (HTML level) and by the keyboard pass of the browser check described under "Manual tests". It has not been tested with a real screen reader.

---

## ⚡ Quick Start (`working-php`)

1. **Navigate to the Active Codebase:**
   ```bash
   cd working-php
   ```

2. **Install Dependencies & Build Assets:**
   ```bash
   npm install
   npm run build
   composer install
   ```

3. **Configure Environment:**
   ```bash
   cp .env.example .env
   # Edit .env to set DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD
   ```

4. **Initialize & Seed Production Database:**
   ```bash
   composer run seed:prod            # creates the tables, the first accounts and the keys in .env
   # On a database that already holds documents or users it refuses; to rebuild it and delete everything:
   #   composer run seed:prod -- --force
   ```

5. **Launch Local Server & Background Worker:**
   ```bash
   # Starts the Web Server, PostCSS Watcher, and Async Queue Worker seamlessly
   composer run dev
   ```

6. **Before using it with real documents,** apply the [Vendor-Recommended Security Baselines](#-vendor-recommended-security-baselines-working-php): change the seeded passwords, set `BACKUP_PASSWORD`, and serve it with `composer run prod`.

---

## 🌱 Database Seeding Commands (`working-php/scripts/seeders`)

- **Production Setup (`composer run seed:prod` / `node scripts/seeders/setup-production-db.js`):** Builds database tables from `database.sql` and seeds 15 DepEd departments (including the ICT Unit), baseline document purposes, and initial user accounts.
- **High-Speed Direct Database Seeder (`composer run seed:fast [docs]` / `php scripts/seeders/fast-seed.php`):** Generates up to 50,000+ document logs directly in MySQL in seconds by pre-decrypting Ed25519 keys into RAM.
- **Slow-Speed API Seeder (`composer run seed:dev [docs] [chunk] [concurrency]` / `node scripts/seeders/seed.js`):** Dispatches live HTTP requests against the web server to simulate multi-user traffic and session handling, also functions as a concurrency test. **Set `RATE_LIMIT_ENABLED=false` in the server's `.env` (and restart PHP-FPM) while it runs, and back to `true` afterwards:** it submits thousands of documents from one IP, and the public routes allow only 10 submissions per IP per minute. Without the switch the seeder stops at the first HTTP 429 with a message saying so. `seed:prod` and `seed:fast` write to the database directly and are not limited.

---

## 🛠 Script Directory Guide (`working-php/scripts`)

| Command / Script | Purpose |
| :--- | :--- |
| `composer run worker` | Runs [console.php](working-php/console.php) background job processor and telemetry scheduler. |
| `composer run dev` | Runs [scripts/dev.php](working-php/scripts/dev.php) to start local dev server & Tailwind watcher, plus the queue worker with a watchdog that restarts it if it exits (Ctrl+C stops it too). |
| `composer run prod` | Runs [scripts/prod.php](working-php/scripts/prod.php) for a server: restarts PHP-FPM (flushes OPcache) and nginx, then keeps running until Ctrl+C. It does **not** clear `cache/` and does **not** start the queue worker; run the worker as a service (see [Running Continuously on Linux](#-running-continuously-on-linux-working-php)). |
| `composer run corrupt` | Runs [scripts/corrupt.php](working-php/scripts/corrupt.php) to simulate database tampering and test the JIT pre-action integrity check and automatic freezing. |
| `php scripts/tune-database.php` | Analyzes system memory and outputs optimal MySQL `my.cnf` buffer pool configurations. |
| `php scripts/generate-keys.php` | CLI tool for generating missing Ed25519 digital signature keypairs. `--system` creates the server key used to sign system actions (`SYSTEM_SIGNING_SECRET` / `SYSTEM_SIGNING_PUBLIC` in `.env`). |
| `php scripts/create-checkpoint.php` | Writes a signed chain-head checkpoint to `storage/checkpoints/` (the worker also does this daily). Copy checkpoints off the server. |
| `composer run test` / `test:security` / `test:concurrency` / `test:workflow` / `test:integrity` / `test:<name>` | PHPUnit suites, thesis groups and single test classes, see [Testing](#-testing-working-php). |
| `composer run evidence` | Thesis evidence set in one results folder, see [Testing](#-testing-working-php). |
| `composer run bench` | Performance benchmark (state/chain hashing, log reads, pre-action integrity check, Argon2id, AES unwrap, Ed25519, HTTP timings); writes `benchmark.csv` into the run folder under `storage/test-results/`. Replaces the former `benchmark-crypto.php`. |
| `composer run replay -- <csv>` | Runs [scripts/route-replay.php](working-php/scripts/route-replay.php) to replay the legacy routing export against the route suggester. |
| `scripts/migrations/master-migration.sql` | The single, idempotent migration for existing databases; new schema changes are appended to it (see the migration notice under Testing). |

---

## 🔁 Running Continuously on Linux (`working-php`)

The DTS is meant to run without stopping. Everything that grows over time falls into one of three groups:

| Group | What | Who keeps it bounded |
| :--- | :--- | :--- |
| Handled by the app | Page cache (purged hourly), `database_metrics` (rolled up to hourly after 24 h), notifications (newest 10 per user/department), `navigation.log` (renamed at 50 MB), session files in `storage/sessions` (a session idle longer than `SESSION_LIFETIME`, 12 hours by default, is ended on its next request and its file removed by PHP's cleanup), backups older than `BACKUP_KEEP_DAYS`, jobs whose process died (recorded under Failed Jobs) | The worker's scheduler (`console.php`) |
| Handled by the operating system | nginx logs (`/var/log/nginx/dts_*.log`), PHP-FPM log (`/var/log/php<version>-fpm.log`), `storage/logs/worker.log` | logrotate, which must actually run (below) |
| Kept forever | `documents`, `document_logs`, `user_public_key_histories` (the audit trail) and the signed checkpoints in `storage/checkpoints/` | Nobody: plan disk capacity instead (below) |

### 1. Keep the queue worker running

The worker runs the scheduler (daily checkpoint, metrics, cache cleanup, stale-document cleanup, hourly purge of expired rate-limit counters) and the background jobs. Its last-run times are stored in the `worker_schedule` table, so a restart never postpones a daily task. It still has to be restarted when it exits. `composer run dev` does this itself (a watchdog relaunches it within about 5 seconds). On a server, run it as a systemd service, which also starts it at boot:

```bash
cd /opt/dts/working-php                       # your project path (preferably without spaces)
sed -e "s#__PROJECT_ROOT__#$PWD#g" -e "s#__USER__#$USER#g" deploy/dts-worker.service \
    | sudo tee /etc/systemd/system/dts-worker.service > /dev/null
sudo systemctl daemon-reload
sudo systemctl enable --now dts-worker.service
systemctl status dts-worker.service           # should say "active (running)"
journalctl -u dts-worker.service -n 50        # worker start-up messages (output also goes to storage/logs/worker.log)
```

Serve the site with `composer run prod` on that server, not `composer run dev`. `dev` starts and watches its own worker, which would duplicate the service. Existing databases need `scripts/migrations/master-migration.sql` for the `worker_schedule` table.

**`composer run dev` vs `composer run prod`:**

| | `composer run dev` | `composer run prod` |
| :--- | :--- | :--- |
| Meant for | A developer's machine | The server, running until the next update |
| PHP-FPM and nginx | Restarted (OPcache flushed) | Restarted (OPcache flushed) |
| `working-php/cache/` | The files directly in it are deleted at start, including `integrity-check-result.json`, so System Overview shows "Last checked: Never" until the next check. Cached pages in `cache/responses/` are left to the worker's hourly cleanup. | Not touched; the last integrity result stays |
| Queue worker | Started and watched (restarted within about 5 seconds if it exits), stopped by Ctrl+C | **Not started.** Without `dts-worker.service`, queued jobs (Run Check, backups, reports) never run and no checkpoint is written |
| Output | Follows the nginx access log | Waits silently until Ctrl+C |

**Queue job settings** (`.env`, optional): `JOB_HEARTBEAT_SECONDS` (default 10) is how often a running job's helper process reports that the job is alive; `JOB_ABANDONED_SECONDS` (default 60, never less than three heartbeats) is how long without a report before the worker records the job under Failed Jobs. Such a job is not retried. If MySQL is down, the worker exits and the service (or the `composer run dev` watchdog) starts it again; after any restart or pause it waits one round before checking for abandoned jobs, so running jobs are not recorded by mistake.

**Secrets, passwords and the other security settings** are in [Vendor-Recommended Security Baselines](#-vendor-recommended-security-baselines-working-php).

### 2. Make sure the operating system rotates logs

Ubuntu and Debian already install the rules: `/etc/logrotate.d/nginx` (daily, keep 14, compressed), `/etc/logrotate.d/php<version>-fpm` (weekly, keep 12),. The DTS keeps its own sessions in `storage/sessions` and removes expired ones itself, so the system's PHP session cleaner (`phpsessionclean.timer`) is not needed for it. The rules only help if logrotate is installed and something runs it. Minimal images (containers, Distrobox) often lack the `logrotate` package itself:

```bash
command -v logrotate || sudo apt install logrotate     # installs logrotate.timer and /etc/cron.daily/logrotate
```

**On a machine with systemd** (a normal server or desktop install), check that the timer is active:

```bash
systemctl list-timers logrotate.timer
sudo systemctl enable --now logrotate.timer    # if it is missing or inactive
```

**On a machine without systemd as PID 1** (Docker, Distrobox, WSL), timers never fire. Install and start cron, which runs `/etc/cron.daily/logrotate`:

```bash
sudo apt install cron
sudo service cron start            # start it again after every container or WSL restart
```

**Add the worker log** (the systemd unit and `composer run dev` both append to it):

```bash
cd /opt/dts/working-php
sed -e "s#__PROJECT_ROOT__#$PWD#g" -e "s#__USER__#$USER#g" deploy/dts.logrotate \
    | sudo tee /etc/logrotate.d/dts > /dev/null
sudo logrotate -d /etc/logrotate.d/dts                 # dry run: shows what would be rotated, changes nothing
sudo logrotate -d /etc/logrotate.d/nginx
```

### 3. Plan disk space for what is kept forever

The audit trail and the checkpoints are never deleted, because removing either would weaken tamper detection. Each daily checkpoint lists every document's chain head, so it grows with the number of documents: about **8.5 MB per day at 100,000 documents** (about 3 GB per year), and about 25 MB per day at 300,000 documents. Copy them off the server regularly ([Vendor-Recommended Security Baselines](#-vendor-recommended-security-baselines-working-php), section 5). Backups in `storage/app/backups/` expire: each new backup deletes those older than `BACKUP_KEEP_DAYS` (default 14 days), always keeping the newest; set it to `0` to keep every backup.

### 4. Update a running server

`composer run prod` runs until the next update. To install one:

```bash
cd /opt/dts/working-php
sudo systemctl stop dts-worker.service      # once the jobs table is empty: queued jobs store their class names
git pull
composer install --no-dev                   # regenerates vendor/autoload (required after files move)
npm install && npm run build                # only if CSS or Tailwind changed
mysql -u <user> -p deped_dts < scripts/migrations/master-migration.sql   # safe to run every time; adds what is missing
# stop the running `composer run prod` (Ctrl+C), then start it again so PHP-FPM reloads the new code
composer run prod
sudo systemctl start dts-worker.service
```

Check the CHANGELOG's "Upgrade note" for the version you are installing. An update does not clear `cache/`, so the last integrity result and the signed checkpoints are kept. Run an integrity check from System Overview afterwards.

## 🔒 Vendor-Recommended Security Baselines (`working-php`)

The settings and habits the developers recommend for any server that holds real documents. Every security setup step is here; other sections link to this one. Work through it after the first install and after every update, then run the check in section 8.

### 1. Secrets in `.env`

| Key | What it protects | How it is created |
| :--- | :--- | :--- |
| `SYSTEM_SIGNING_SECRET` / `SYSTEM_SIGNING_PUBLIC` | Signs system log entries and the daily checkpoints | `php scripts/generate-keys.php --system` (`composer run seed:prod` runs it) |
| `APP_INTEGRITY_KEY` | Keys the system audit log, `storage/logs/system-audit.log` | `php scripts/generate-keys.php --integrity` (`composer run seed:prod` runs it) |
| `BACKUP_PASSWORD` | Encrypts backup archives with AES-256 | You choose it and type it into `.env` |
| `DB_PASSWORD` | The database account | You set it in MySQL and in `.env` |

- **Set `BACKUP_PASSWORD` before the first backup.** Without it backups are still made, unencrypted, and the Backups page shows a warning. A backup holds password hashes, wrapped signing keys and clients' contact details.
- **Keep a copy of all of them away from the server** (a sealed envelope or a password manager). A backup cannot be restored without the password it was made with. Replacing `SYSTEM_SIGNING_SECRET` or `APP_INTEGRITY_KEY` makes existing log entries, checkpoints or audit lines fail verification, so the scripts refuse to overwrite a key unless you pass `--force`.
- **Only the account that runs the app may read `.env`:** `chmod 600 working-php/.env`. NGINX serves `working-php/public/` only and refuses dot-files, so `.env` is never reachable from a browser.
- Keep `APP_DEBUG=false`. With `true`, PHP errors (file paths, query text) are printed to the page.

### 2. Accounts, passwords and PINs

- **Change the password of every seeded account before going live.** They all start as `password` (see [Default Test Accounts](#-default-test-accounts-password-password)). Each account's owner signs in and uses **Change password** in the user menu (`/account/password`); an admin cannot type another person's password. The sign-in password is part of your sign-in credentials; it proves who you are when you log in.
- **Password rules.** A new password has at least 10 characters (any mix of letters, numbers and symbols), differs from the current one and from `password`, and is not the user's Security PIN. Five wrong current passwords within a minute time the user out for one minute, and an account can change its password at most five times per minute. A user creates their Security PIN first and can then change the password (the change-password form is refused until a PIN exists).
- **New accounts start with the password `password`.** The admin creates an account without typing a password. An account is flagged for a reminder when an admin creates it or resets its sign-in password, and when someone signs in with `password`. While flagged, every sign-in leaves one notification ("You are using the default password." or "Your password was set by an administrator.") with a **Change password** link; it pops up as a toast on the first page after sign-in and then stays in the notification list. Nothing is forced: the account works as usual until its owner changes the password, which removes the flag and the reminder. Changing your password signs you out everywhere, including the current session; an admin reset of the sign-in password signs that user out of all their sessions (a Security PIN-only reset does not).
- **One account per person or unit; never delete, deactivate.** A deactivated account can no longer sign in, and everything it signed stays verifiable. Staff and Records Officer accounts need a department; a Records Officer belongs to the Records Unit.
- **Each user sets their own Security PIN** at first login and must not share it: the PIN is what makes a signature theirs. The Security PIN is a second passcode that unlocks your signing key; you enter it to sign an action on a document. A new PIN has at least 10 characters (any characters) and must differ from the sign-in password; using the same passcode for sign-in and for signing is a security risk, because anyone who learns one would have both. Existing shorter PINs keep working until they are reset. A Security PIN can only be replaced through an admin reset; the PIN form refuses an account that already has one, and repeated PINs equal to the sign-in password lock the form for one minute.
- **Resetting credentials.** On **Users**, **Reset** opens a modal with three choices: **Sign-in password** (back to `password`, account flagged), **Security PIN** (clears the key; the old key is kept so earlier signatures still verify) or **Both**. Confirming asks for the admin's own Security PIN, and each reset is recorded in the system audit log as `user_credentials_reset`. The seeders still sign actions with the PIN `password`, so seeded PINs are shorter than 10 characters; reset them before going live.
- **Built-in limits** (no setup needed, listed so you know what to expect): the public guest routes limit each client IP per minute (`POST /submit-document` 10, `GET /documents/{code}/print-tracking-form` 10, `GET /track` 60, `GET /api/track/module/{code}` 120, `GET /api/track/status` 240); a request over the limit gets HTTP 429 with `Retry-After` (a page, or JSON under `/api/`), and the limits apply to signed-in users on those routes too. They are the constants in `RateLimitMiddleware`, the client is `REMOTE_ADDR` only (never `X-Forwarded-For`), and **`RATE_LIMIT_ENABLED` in `.env` must stay `true` on a real server** (absent means on). Kiosks behind one shared address share the counters. The limits are per IP address (each IPv6 address counts separately). A request refused for a missing or outdated security token (HTTP 419) is refused before the rate limit is counted. Counters are kept in the `rate_limits` table under a hash of route and IP, and the worker deletes expired ones hourly. Existing databases need `scripts/migrations/master-migration.sql`; until the table exists the limiter lets every request through and logs a line. Five wrong passwords or PINs within a minute lock that login or PIN for one minute (a failed sign-in keeps the username and shows one message that does not say which of the two was wrong); a session idle for `SESSION_LIFETIME` seconds (default 43200, 12 hours) is ended on its next request; the PIN is remembered for at most `PIN_CACHE_DURATION` seconds and never longer than the session.
- **Existing databases:** run `scripts/migrations/master-migration.sql` once (see [Update a running server](#4-update-a-running-server)). Until its `attempt_throttles` table exists, login and PIN limiting are off and a line is written to PHP's error log. The password reminder and the notification links need its `users.must_change_password`, `users.session_version`, `notifications.link` and `notifications.link_label` columns; without them, signing in with the default password and any page that stores a notification fail, and every signed-in request fails because the session check reads `users.session_version`.

### 3. Network and the web server

- **LAN only.** The system is designed to run on the office network with no route from the internet. Do not forward its port on the router.
- **Serve it with `composer run prod`** (NGINX + PHP-FPM over HTTPS on port 8000), never `composer run dev`, on the real server. HTTPS is required for the QR camera and keeps passwords and PINs off the wire; the certificate is self-signed, so each device accepts it once. When PHP-FPM is not running NGINX answers 502 (also 503 and 504) with the static page `public/errors/gateway.html` instead of its bare default. `composer run prod` and `composer run dev` write the NGINX site file from `nginx/site.conf` each time they start; a server whose site file was installed some other way needs the updated `nginx/site.conf` (its `error_page 502 503 504` line and the `location = /errors/gateway.html` block) and an NGINX reload.
- **Never run `composer run proxy` on the real server.** It opens a public Cloudflare tunnel to the app and exists only for remote reviews during development.
- Sessions are kept in `working-php/storage/sessions`, not the folder all PHP apps share. Run the web server and the worker as the same user, so both can write to `storage/`.

### 4. Backups

- **Download, restore and delete ask for the admin's Security PIN**, and each is recorded in the system audit log; a link alone no longer serves a backup. Every restore first saves the current database as a `…_pre-restore_…` backup, so a restore can be undone.
- **Download backups to another machine regularly.** A backup that exists only on the server is lost with it. Backups older than `BACKUP_KEEP_DAYS` (default 14) are deleted when a new one is made; the newest always stays.
- **Intermediate files live in `storage/temp/`.** A backup or restore writes its plain SQL file into its own folder there and removes it when it finishes, so `storage/app/backups/` holds only finished `.zip` files. Each folder is locked while its job runs. If a job is killed, the next backup or restore clears what it left behind once no running job holds the folder.
- **Never run `composer run seed:prod -- --force` on a server with real data** unless you mean to delete all of it. Without `--force` the seeder refuses a database that holds documents or users.

### 5. Evidence kept off the server

The audit trail is tamper-evident, not tamper-proof (see the known limitation under [Testing](#-testing-working-php)): someone who controls both the database and the server could rewrite it. What defeats that is evidence they cannot reach.

- **Copy the newest file in `storage/checkpoints/` off the server regularly** (weekly, to USB or another machine). A checkpoint is a signed list of every document's latest log entry; with an old copy you can prove that entries were deleted or rolled back.
- **Copy `storage/logs/system-audit.log` with it.** It records backup requests, restores and deletions outside the database. Lines cut off its end cannot be detected from the file alone; an older copy shows them.
- **Separate duties:** the person who administers the server should not be the only one who can administer the database, and administrator access to both should be restricted and logged.

### 6. Routine checks

- **Run Integrity Check** on System Overview at least weekly, and after any restore or update. Expect 100 % chain integrity, 0 broken links, 0 invalid signatures, 0 live-state mismatches, 0 checkpoint heads missing, and "System audit log: intact".
- **Look at Failed Jobs** on the same page. A job that "stopped sending its health ping" was cut short by a crash or a restart; start it again if it is still needed.
- **Frozen documents** mean a document's data no longer matches its signed log. Do not unfreeze one until you know why (admin manual, 4.3).

### 7. Keep the service itself running

Security depends on the worker: it writes the daily checkpoint and runs integrity checks, backups and restores. Install it as a service and make sure logs are rotated, as described in [Running Continuously on Linux](#-running-continuously-on-linux-working-php).

### 8. Check after install or update

1. `php scripts/generate-keys.php --system` and `--integrity` both answer "already present" (the keys exist).
2. `BACKUP_PASSWORD` is set: open **Backups**; no "stored unencrypted" warning is shown.
3. Restart `composer run prod` (or `composer run dev` on a development machine). Everyone signs in again once after an update that moved the sessions.
4. Open **Backups** through the menu, without reloading the page, and delete a backup you no longer need: it asks for your PIN.
5. Run **Integrity Check**: 100 %, and "System audit log: intact".
6. Sign in as each seeded account with the password `password`: it must fail on a live server.

### 9. Content Security Policy and page scripts

**Status in this version (2.7.10): the policy is sent in report-only mode.** Every PHP response (pages, JSON, redirects, error pages, downloads) carries `Content-Security-Policy-Report-Only` (`App\Common\Http\SecurityHeaders`, called once from `public/index.php`). A browser reports what the policy would block (console message, `securitypolicyviolation` event) and blocks nothing. The policy is `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`: everything comes from this server, inline styles are still allowed, nothing is embedded or framed. It is not enforced yet and the NGINX configuration is unchanged; enforcing it (and the static `public/errors/gateway.html` of NGINX) is the next slice. Browsers do not apply `frame-ancestors` in a report-only policy (it only takes effect once the policy is enforced).

**How page scripts are organized (for contributors).** No page contains an inline `<script>` or an event handler attribute (`onclick=` and the like), so `script-src 'self'` can be enforced. All JavaScript is in `working-php/public/js/`:

- `core/` is the page registry (`DtsPage`) and the JSON data reader (`DtsData`); `layout/` is what the layouts need on every page (theme, menus, notifications and toasts, global confirm, PIN setup prompt); `components/` is behaviour of a reusable piece (signing PIN prompt, sign actions, copy buttons, QR scanners); `pages/` is one file per page that has its own behaviour. The libraries (`chart.min.js`, `html5-qrcode.min.js`, `sortable.min.js`) are local files and are not edited.
- The layouts (`src/Views/layouts/app.php`, `guest.php`) load every script file once, deferred, in the head. The router never runs scripts. To add a script, create the file, add its tag to the layout list, and stop there.
- Behaviour that needs no setup per page uses **event delegation** on `document` with `data-` attributes or classes (for example `data-copy-text`, `.sign-action`, `.confirm-action`, `.open-modal-btn`), bound once when the file loads, so it also works for content that arrives with in-app navigation.
- Setup that belongs to one page is registered with `DtsPage.onLoad('[data-page="name"]', function (root) { ...; return function teardown() {...}; })`. The initializer runs on the first load and after every in-app navigation when the root element is present, never twice for the same element; the teardown it returns runs before the content is swapped. **Stop every timer, poll, Chart instance and pending job in that teardown**: page scripts never listen to `dts:before-swap` themselves and a poll must not rely on the router's abort signal (that only cancels requests in flight).
- Shared code is written once: `SigningModal.submitWithPin(form, pinInput, message, opener, {clearAfter})` is the one PIN-prompt flow, `DtsPinToken` the one owner of the PIN cache token key, `DtsUtil.csrfToken()` and `DtsUtil.formatRemainingTime()` the helpers; use them instead of copies. Put `data-page="name"` on a root element inside the page's view.
- **Server data reaches scripts only through escaped `data-*` attributes or a JSON block**: `<?= \App\Common\Utils\JsonDataBlock::render('element-id', $data) ?>` (the one PHP helper; it escapes `<`, `>`, `&` and quotes so `</script>` in data cannot break out) read with `DtsData.read('element-id', fallback)` (the one JS helper). Never write PHP into a `.js` file and never build script text from request or database data. Insert text into the page with `textContent` or DOM methods, not `innerHTML`.
- Checked by `composer test:no-inline-script`, `composer test:content-security-policy` and `composer test:json-data-block`, and by `behavior-check.mjs` of the browser check (see "Manual tests"), which records every violation, console error and uncaught exception per page.

## 🧪 Testing (`working-php`)

**Setup.**

```bash
cd working-php
cp .env.testing.example .env.testing
APP_ENV=testing php scripts/generate-keys.php --system   # writes the system key into .env.testing
APP_ENV=testing php scripts/generate-keys.php --integrity   # writes the integrity key into .env.testing
```

You do not create the database by hand: `TestLifecycle` (called from `tests/bootstrap.php`) drops and recreates `deped_dts_test` and the `working-php/.test/` folder before every run, and removes them again after it. Every file a test creates lives in `.test/` (gitignored): the test response cache, checkpoints, integrity result, backups, navigation log and temporary files (`Tests\Support\TestFiles`). Result CSVs are kept. Only `.env.testing` and the system key are one-time setup. Within a run, the suite resets `deped_dts_test` from `database.sql` before every test, and the security tests deliberately corrupt and overwrite data. It therefore refuses to run unless `APP_ENV=testing` and the database name ends in `_test`. The test environment also uses its own response cache, checkpoint directory and integrity-result file so it never touches real data.

**Commands** (from `working-php/`).

| Command | What it runs |
| :--- | :--- |
| `composer test` | Unit, workflow and HTTP suites only. |
| `vendor/bin/phpunit` | Default suites: unit, workflow, HTTP and security (concurrency and benchmark are excluded by group). |
| `composer test:security` | Tamper-injection tactics and HTTP attack tests, tagged by threat actor. |
| `composer test:concurrency` | Kiosk-burst and simultaneous-action tests against `php -S` started with 16 workers (`PHP_CLI_SERVER_WORKERS=16`) with rate limiting off (100 submissions from one IP); set `TEST_BASE_URL` to target an nginx/FPM deployment instead, which then must run with `RATE_LIMIT_ENABLED=false`. The parallelism check is timing-sensitive and can fail on a heavily loaded machine. |
| `composer test:workflow` | Thesis: document workflow from submission and intake to receive, complete and release (`LifecycleTest`), with the department-step rules for officers and admins (`OfficerStepRuleTest`, `AdminStepRuleTest`). PHPUnit group `thesis-workflow`. |
| `composer test:integrity` | Thesis: hash-chain integrity: the tamper matrix and the 100 % control (`TamperInjectionTest`), signature verification (`SignatureVerificationTest`) and signer protection (`UserLifecycleIntegrityTest`). PHPUnit group `thesis-integrity`. |
| `composer run evidence` | Thesis evidence set in one folder: all default suites (unit, workflow, HTTP, security) ×1, `test:workflow` ×1, `test:integrity` ×`EVIDENCE_RUNS`, and the concurrency suite once with `CONCURRENCY_RUNS=EVIDENCE_RUNS` (default 5). Every run still executes when one fails; the command exits 1 if any run failed. `composer run evidence -- --list` prints the commands without running them. |
| `composer bench` | Performance benchmark. Seeds through `scripts/seeders/fast-seed.php` (the `seed:fast` logic, direct DB inserts); 100,000 documents took 83.8 s in a logged run, so 1,000,000 takes about 14 minutes if it scales linearly. Defaults to 1,000,000 documents and 1,000 iterations; full run: `BENCH_DOCS=1000000 BENCH_ITERATIONS=1000 composer bench`. Smaller runs: e.g. `BENCH_DOCS=10000`. Set `BENCH_HTTP=0` to skip HTTP timings. The guest `/track` loop runs against a server with rate limiting off; with `TEST_BASE_URL` the external server needs `RATE_LIMIT_ENABLED=false`. |
| `composer replay -- <path-to-legacy-csv>` | Replays the legacy routing export (`dts_docroutes` CSV) through `HeuristicRoutingService` (`src/Modules/Routing/HeuristicRoutingService.php`); optional second argument is the output directory, and `--records-names="A\|B"` overrides the Records intake section names. Besides the heuristic it scores the baselines `none`, `frequent_so_far` and `static_hindsight` (look-ahead, hindsight) into `route-replay-baselines.csv`, and writes `route-replay-purpose-profile.csv`. |

**One script per test class.** Each test class also runs on its own with `composer run test:<name>` (the class name without `Test`, in kebab-case); `composer list` shows the same descriptions. Concurrency and benchmark scripts pass their `--group`, since `phpunit.xml` excludes those groups by default. `ComposerScriptsTest` fails if a test class has no script, description or README entry.

*Document workflow*

| Command | What it checks |
| :--- | :--- |
| `test:lifecycle` | Full document lifecycle (submit, intake, receive, complete, release) keeps one tracking code and verifies at 100 %; a wrong-department scan is rejected. |
| `test:officer-step-rule` | Records Officers receive and complete only at their department's step; intake, decline and release stay with them. |
| `test:admin-step-rule` | Admins follow the same department-step rule for receive and complete; freeze and unfreeze stay admin-only. |
| `test:route-validation` | Intake rejects routes naming unknown departments before any write; route errors are escaped. |
| `test:route-learning` | Finalizing an official purpose's document updates its suggested route; "Others" does not. |
| `test:heuristic-routing-service` | Route suggestion rules: guest department first, no suggestion for "Others", legacy route formats. |
| `test:unfreeze-status` | Unfreeze and auto-resolve restore the real status, and only from a verified snapshot. |
| `test:tracking-code-collision` | Guest submission retries on a duplicate tracking code; seeded codes contain no sequential counter. |
| `test:tracking-code-util` | Tracking-code format, with no time-based input. |
| `test:guest-department-validation` | The guest form accepts only departments that exist (exact case). |
| `test:decline-redirect` | Declining redirects to the dashboard with a toast; a failed decline returns to the referring page. |

*Concurrency*

| Command | What it checks |
| :--- | :--- |
| `test:guest-submission-concurrency` | 100 simultaneous kiosk submissions get unique, matching tracking codes; the server handles requests in parallel (CONCURRENCY_WORKERS, CONCURRENCY_RUNS). |
| `test:task-completion-concurrency` | Five simultaneous completions of one task: exactly one succeeds through the optimistic version check (CONCURRENCY_RUNS). |

*Integrity and signatures*

| Command | What it checks |
| :--- | :--- |
| `test:tamper-injection` | Tamper matrix: each privileged-insider tactic against the pre-action check and the integrity audit; untouched chains report 100 %. |
| `test:signature-verification` | Every Ed25519 signature verifies; altering any signed field fails verification; signatures survive a key reset. |
| `test:user-lifecycle-integrity` | Users who signed log entries or have key history cannot be deleted, even with raw SQL; chains stay at 100 %. |
| `test:key-cache-invalidation` | Entries signed after a key reset verify within the same long-lived process. |
| `test:core-module-structure` | Module structure guard: old classes removed, log format v2 byte-identical, tampered documents frozen. |

*Accounts and authentication*

| Command | What it checks |
| :--- | :--- |
| `test:user-deactivation` | The users page deactivates and reactivates accounts instead of deleting them; only admins can, never on themselves. |
| `test:deactivated-login` | Deactivated accounts cannot sign in and are signed out on their next request (401 JSON for API polling). |
| `test:user-service` | Deactivation and reactivation rules of user accounts. |
| `test:user-department` | Creating or editing an account assigns its department: required for staff and Records Officers, Records Unit for officers, none for admins. |
| `test:session-refresh` | A signed-in user's role and department follow the database on the next request (demotion, department move). |
| `test:user-list-filter` | The users page role filter narrows by a real role; "All Roles" and unknown values list everyone. |
| `test:validation-message` | Validation errors are shown as plain text with no <br> markup. |
| `test:failed-job-delete` | Deleting one or all failed jobs returns the admin to System Overview. |
| `test:intake-date-filter` | The intake and completed-task lists filter by the day the user handled the document. |
| `test:login-rate-limit` | The fifth failed login times out the account; a correct password is refused while locked; a failed login keeps the username and shows one message for every kind of failure. |
| `test:pin-rate-limit` | The fifth wrong Security PIN times out signing; a cached token does not count as a failure. |
| `test:attempt-throttle-service` | Failed-attempt limiting: window, lock duration, per-email and per-user keys. |
| `test:throttle-table-missing` | Login and PIN signing fail open when the attempt_throttles table is missing. |
| `test:freeze-all-throttle` | Freeze All reports a timed-out PIN instead of freezing nothing silently. |

*Security attacks*

| Command | What it checks |
| :--- | :--- |
| `test:frontend-attack` | CSRF, stored XSS, forged routes, wrong-department scans and staff reaching admin routes are refused. |
| `test:sql-injection` | SQL injection payloads in every input have no effect. |
| `test:pin-token` | Signing tokens are useless in another session, after tampering or after expiry; a wrong PIN writes nothing. |
| `test:document-visibility` | Document and hash-chain pages honour DocumentPolicy::view. |

*Pages and HTTP behaviour*

| Command | What it checks |
| :--- | :--- |
| `test:document-toast-code` | Toasts name the document's tracking code once. |
| `test:layout-assets` | The app layout loads no client-side crypto library, and signing still works. |
| `test:accessibility-markup` | Pages have one h1 and no skipped heading level; dialogs, buttons and form controls are labelled; autocomplete hints, live regions and focus styles are in place. |
| `test:no-inline-script` | No rendered page has executable inline JavaScript, an event handler attribute or a javascript: URL; every script is a local file; text in a JSON data block cannot break out of it. |
| `test:content-security-policy` | Every PHP response (page, JSON, redirect, error page, download) carries the Content Security Policy in report-only mode, and none enforces it yet. |
| `test:json-data-block` | The JSON data block helper escapes script and comment endings, quotes and ampersands, and refuses unsafe element ids. |
| `test:tracking-form-print` | Print-tracking-form links on officer pages, none for staff; the slip PDF is served only to the submitting session or to staff allowed to view the document. |
| `test:cache-query-string` | The response cache stores bare URLs only; URLs with a query string create no cache files. |
| `test:login-timing` | An unknown username takes about as long to refuse as a wrong password (one bcrypt check either way). |
| `test:released-report-service` | Report rows are text cells only (no formula injection); report filters are whitelisted. |
| `test:report-filters` | Stored report filters carry no CSRF token; a formula-like title is downloaded as text. |
| `test:report-ownership` | A report job is downloaded, inspected and cancelled only by the user who requested it. |
| `test:verify-hash-label` | System Overview uses the "Verify Hash" label. |
| `test:ict-unit` | The production seeder includes the ICT Unit, and intake can route to it. |
| `test:debug-logging` | Requests do not write the temporary router log; the navigation log still records them. |

*Operations and data*

| Command | What it checks |
| :--- | :--- |
| `test:master-migration` | The master migration upgrades a pre-deactivation schema and is idempotent. |
| `test:restore-backup-migration` | Restoring an older backup re-applies the migration, so the schema stays current. |
| `test:worker-schedule` | Scheduled worker tasks run on time; restarts never postpone them. |
| `test:queue-service` | One worker claims a job; a job whose health ping stopped is recorded under failed jobs and not retried. |
| `test:job-heartbeat` | The heartbeat helper pings while a job runs and stops when the runner or the job is gone; runner.php cleans up after success and failure. |
| `test:database-reconnect` | A long-running process re-opens its database connection after MySQL has dropped it, on Philippine time. |
| `test:mysql-cli` | mysqldump and mysql run as argument arrays with no shell; errors are thrown and never written into the dump. |
| `test:backup-service` | Backups go to their own folder, carry the database name, leave out queue and cache tables, restore deleted data, and expire by age (newest always kept). |
| `test:restore-backup-job` | A restore first saves the current database, is recorded in the system audit log with counts, and notifies the admin; failures restore nothing. |
| `test:backup-pin` | Downloading, restoring or deleting a backup needs the admin's Security PIN; wrong PINs lock out; backup actions are recorded in the audit log. |
| `test:account-password` | Self-service password change with lockout, the default-password reminder, and the 10-character rule for passwords and Security PINs. |
| `test:pjax-redirect` | A redirect during in-app navigation is announced in X-PJAX-Location instead of being followed, so its flash message survives. |
| `test:nginx-error-page` | The static gateway error page exists, is self-contained, and every NGINX config routes 502, 503 and 504 to it (live NGINX not exercised). |
| `test:safe-redirect` | Redirect targets from headers or scripts are reduced to a local path and query, or refused; the Referer fallback; the shared relative-path check. |
| `test:error-page` | Error pages and JSON for 403, 404, 419, 429, 500, 503 and other statuses with the right back link and Retry-After; every status the application produces (403, 404, 419, 429, 500) is reached by a real request and shown as the shared page, and the unproduced ones (405, 503) are a checked reserved set; a real 500 shows no exception text or paths; a failing request redirects back only to a same-host Referer (a failing GET without one shows the 500 page, a failing POST redirects back), and partial output is discarded. |
| `test:credential-reset` | An admin resets a user's sign-in password, Security PIN or both after entering their own Security PIN; each reset is recorded in the audit log. |
| `test:system-audit-service` | The system audit log chains its entries with a keyed MAC; edited, removed and reordered lines are detected. |
| `test:integrity-audit-log` | The integrity check verifies the system audit log; System Overview shows it intact or names the altered lines. |
| `test:worker-watchdog` | The dev worker watchdog finds the worker process, also after its re-exec. |
| `test:seeder-duplicate-retry` | The fast seeder recovers from duplicate tracking codes, ignores the test override outside testing, and does not touch real sessions or the response cache. |
| `test:cache-middleware-write` | The response cache writes atomically and treats empty or unreadable files as a miss. |

*Performance*

| Command | What it checks |
| :--- | :--- |
| `test:performance-benchmark` | Operation timings against the sub-second target on a seeded database (BENCH_DOCS, BENCH_ITERATIONS, BENCH_HTTP). |

*Test harness and research tooling*

| Command | What it checks |
| :--- | :--- |
| `test:route-replay` | Legacy route replay: counts, baselines, tie-breaking and CSV output on a small fixture. |
| `test:evidence-runner` | `composer run evidence` runs every thesis step into its own subfolder of one evidence folder, with a README index. |
| `test:stats` | Median and 95th-percentile helpers. |
| `test:barrier` | The live-concurrency barrier keeps partial results when a worker fails. |
| `test:live-run-cleanup` | The live concurrency script deletes only its own documents and refuses unsafe runs. |
| `test:results-extension` | The PHPUnit results extension writes test-outcomes.csv, summary.csv and run.json. |
| `test:result-writer` | Result folders: DTS_RESULTS_DIR containment, run-id validation, no overwriting. |
| `test:test-artifact-paths` | Every file a test creates is under `working-php/.test/`; no test uses the system temp directory. |
| `test:seed-guard` | The production seeder's guard refuses a database that holds documents or users unless --force is passed, and the API seeder stops with a clear message on HTTP 429. |
| `test:session-storage` | Sessions are stored in the app's own folder, cleaned up by the app, with a 12-hour idle limit and hardened cookies. |
| `test:test-guard` | Tests refuse to run outside APP_ENV=testing or against a non-_test database. |
| `test:test-lifecycle` | The per-run test database and artifact lifecycle. |
| `test:test-env-probe` | The /__test/env probe reports the testing environment and database. |
| `test:http-client-probe` | The test HTTP client refuses servers that are not running the testing environment. |
| `test:composer-scripts` | Every test class has a composer script with a description, listed in the README. |
| `test:rate-limit-service` | Rate limiting: fixed window per bucket and client, window reset, independent buckets and clients, a hit survives a rollback of the main connection, purge of expired rows, hashed keys, fail open. |
| `test:rate-limit-middleware` | The rate-limit middleware: the RATE_LIMIT_ENABLED switch, the client taken from REMOTE_ADDR only, and the five route limits defined once with the limiter first. |
| `test:rate-limit-http` | Per-IP limits on guest submission, tracking, the tracking API and the tracking-form print: 429 page or JSON with Retry-After, independent buckets, recovery after the window, fail open without the table. |
| `test:rate-limit-purge-task` | The hourly worker task that deletes expired rate_limits rows. |

**External server (`TEST_BASE_URL`).** Before attaching, the suite calls `GET /__test/env` and refuses to run unless the server reports `env` `testing` and a database name ending in `_test`. That route is registered only when the app runs with `APP_ENV=testing`; in production it returns 404 like any unknown route. An nginx/FPM run therefore needs a separate PHP-FPM pool (or vhost) for the tests with `env[APP_ENV]=testing`, so it loads `.env.testing`; never point `TEST_BASE_URL` at the production pool.

**Results.** Every run (PHPUnit, `composer replay`, `composer manual:live-concurrency`) writes into its own folder, `working-php/storage/test-results/<run_id>/`, where `<run_id>` is `YYYYMMDD-HHMMSS-xxxx` (4 random hex characters; set `DTS_RUN_ID` to choose it). `storage/test-results/latest` is a symlink to the newest folder. A fatal error or a killed run writes no `test-outcomes.csv` or `summary.csv` (they are written when PHPUnit finishes), though CSVs appended by individual tests remain. `DTS_RESULTS_DIR` is set internally for each run; pointing it by hand at an earlier run folder overwrites that run's files. Old folders are never deleted, and the CSVs that sit loose in `storage/test-results/` from earlier versions are left as they are. `ResultWriter` appends rows, so a file is only appended to within one run. A PHPUnit run (parent process only) writes:

| File | Content |
| --- | --- |
| `test-outcomes.csv` | One row per test (each data-provider case separately): `run_id, suite, class, test, data_set, status, duration_ms, assertions, message, timestamp`. `status` is `passed`, `failed`, `errored`, `skipped`, `incomplete` or `risky`; `suite` is the test directory (unit, workflow, http, security, concurrency, benchmark); `message` is the first line of the failure or skip message. |
| `summary.csv` | One row per suite plus `all`: `run_id, suite, tests, passed, failed, errored, skipped, incomplete, risky, duration_s`. |
| `run.json` | `run_id`, `started_at`, `finished_at`, the command-line arguments, `git describe --always --dirty`, PHP version, CPU model, RAM and the list of files in the folder. The replay and live-concurrency scripts write a `run.json` too. |
| `tamper-matrix.csv`, `http-attacks.csv`, `signatures.csv`, `concurrency.csv` | Evidence rows from the security and concurrency suites (columns as written by each test). |
| `benchmark.csv` | `composer bench`: `operation, n, mean_ms, median_ms, p95_ms, max_ms, docs_in_db, logs_in_db, git_commit, php_version, server, run_at, cpu, ram_gb`. |
| `route-replay-*.csv`, `live-concurrency.csv` | Replay and live-concurrency output; an explicit output directory for `composer replay` overrides the run folder. |

**Evidence folder.** `composer run evidence` writes one folder, `storage/test-results/<YYYYMMDD-HHMMSS>-evidence/` (an existing name gets `-2`, `-3`, …), and points `latest` at it:

```
<YYYYMMDD-HHMMSS>-evidence/
├── README.md            index: status, settings, machine, and per run the command, exit code, duration and test counts
├── run.json             commit, PHP version, CPU, RAM, settings, failed runs
├── full-suite-run-1/    all default suites (unit, workflow, http, security): run.json, summary.csv, test-outcomes.csv, tamper-matrix.csv, http-attacks.csv, …
├── workflow-run-1/
├── integrity-run-1/ …   tamper-matrix.csv, signatures.csv, …
└── concurrency-run-1/   concurrency.csv
```

Each subfolder is an ordinary run folder (the runner sets `DTS_RESULTS_DIR` for each process). The index is rewritten after every run and says **In progress** until the set is complete, so an interrupted set is recognisable. Runs inherit the shell's environment; settings that change results (`TEST_BASE_URL`, `CONCURRENCY_WORKERS`) are recorded in the index and `run.json`. The benchmark and the route replay are not part of the set; to add them to the same folder: `DTS_RESULTS_DIR=$PWD/storage/test-results/<evidence>/benchmark-run-1 composer bench` and `composer replay -- <csv> storage/test-results/<evidence>/route-replay`.

**Threat model.** Every simulated attack is tagged with one of four LAN actors (`tests/Support/ThreatActor.php`):

| Actor | Description |
| :--- | :--- |
| `kiosk_walkup` | Unauthenticated person at a kiosk or on a LAN browser. |
| `lan_device` | Rogue or compromised device on the office network sending crafted HTTP requests. |
| `staff_insider` | Logged-in user acting outside their role or department, or using an unattended unlocked session. |
| `privileged_insider` | Someone with direct database or server access. |

Not applicable on an offline LAN (`ThreatActor::NOT_APPLICABLE`): botnet DDoS, internet credential stuffing, cloud metadata SSRF.

**Known limitation: the fully privileged insider.** The audit trail is tamper-evident, not tamper-proof. Anyone without the users' PINs and the server signing key cannot alter, delete or reorder log entries undetected. However, the tamper matrix shows that someone with database access can register their own key pair in `user_public_key_histories` and append a properly signed entry, and nothing detects it, because public keys are trusted from the same database. Someone who also controls the server holds the server signing key and can re-sign entries and checkpoints. No mechanism stored on the same machine can detect such an insider. The defence is organizational and is set out in [Vendor-Recommended Security Baselines](#-vendor-recommended-security-baselines-working-php), section 5. Certifying user public keys with an offline key is future work.

**Migration notice.** Existing databases must run `working-php/scripts/migrations/master-migration.sql`; what it enables is listed in [Vendor-Recommended Security Baselines](#-vendor-recommended-security-baselines-working-php), section 2. Fresh databases built from `database.sql` already include everything in it.

**Fixed findings.** F1 (finalizing a route accepted an unknown department), F2 (staff could view documents outside their route through `DocumentController::show`) and F3 (`CacheMiddleware` wrote cached pages non-atomically, so a cold-cache kiosk burst could get pages without a CSRF token) are fixed. The former finding tests remain as regression tests, and the default `vendor/bin/phpunit` ends with 0 failures.

**Workflow rules.** Officers are bound by the department-step rule like staff: acting on a document outside their step gives "Action Denied". They keep intake, the final Records receive, and release.

**Seeders.** `fast-seed.php` and `import-legacy.php` rely on the database unique key for tracking codes and retry the chunk on MySQL duplicate error 1062; there is no in-memory duplicate check. Under `APP_ENV=testing`, `fast-seed.php` only clears the test cache in `.test/` and leaves PHP sessions alone.

### Manual tests (you start these)

The live concurrency run is not part of any suite. It fires simultaneous kiosk submissions at the real web app and `deped_dts`:

```bash
cd working-php
COMPOSER_PROCESS_TIMEOUT=0 composer manual:live-concurrency -- --base-url=https://<lan-host>:8000 --confirm-live --insecure
```

- `--workers=N` sets the number of simultaneous submissions (1 to 1000, default 100).
- `--insecure` skips TLS verification; drop it when the server certificate is trusted.
- All workers submit from one IP, and the server allows 10 submissions per IP per minute, so with more than 10 workers the rest get HTTP 429 and count as failures. Set `RATE_LIMIT_ENABLED=false` in the server's `.env` and restart PHP-FPM for the run, then set it back to `true`.
- Do not run it in a shell where `APP_ENV=testing` is exported; it refuses.
- Titles are tagged `CONC-LIVE-<run_id>-<i>`. The script always auto-deletes only its own documents, and prints the run id and the deleted count. "Its own" means rows titled with its run id prefix `CONC-LIVE-<run_id>-…` that were created during the run (id above the highest id seen at start). The run id is `LIVE` plus a timestamp to the second (`YmdHis`), so two runs started in the same second share a run id and each would delete the other's rows. If anything remains, it prints a manual `DELETE` statement.
- It warns if a checkpoint was written during the run, because those chain heads will show as missing in the next audit.
- Results are appended to `storage/test-results/<run_id>/live-concurrency.csv` (each script run gets its own folder).

**Responsive layout, accessibility, script policy and behaviour check (`manual:page-screenshots`).** One launcher runs three browser scripts (`behavior-check.mjs` is described below the other two): `page-screenshots.mjs` screenshots the main pages of every role at 360, 1024 and 1280 px and reports layout overflow; `accessibility-check.mjs` runs a keyboard pass at 1280 and 360 px (see the last bullet below). It uses the TEST database only: it builds fresh fixtures, starts PHP's built-in server in the testing environment with rate limiting off, runs a headless Chromium through `playwright-core` and stops the server again. It refuses to run unless the configured database name ends in `_test`.

**It resets the TEST database (drops every table and recreates the schema) and shares `.test/` with PHPUnit, so never run it while PHPUnit is running, and never run PHPUnit while it runs.** PHPUnit also empties `.test/` when it finishes, which deletes the screenshots.

`composer run manual:page-screenshots` needs no `COMPOSER_PROCESS_TIMEOUT` override: `composer.json` sets `"process-timeout": 0` under `config` for all scripts.

```bash
cd working-php
npm install --prefix .test/browser playwright-core   # once, and again after every PHPUnit run (PHPUnit empties .test/)
composer manual:page-screenshots                      # or: php scripts/manual/page-screenshots.php
```

- Needs a headless Chromium: the default path is `~/.cache/ms-playwright/chromium_headless_shell-1248/chrome-headless-shell-linux64/chrome-headless-shell`; set `CHROMIUM_PATH` to use another binary.
- Output: `working-php/.test/screens/<width>/<page>.png` (at 360 px also `<page>--menu-open.png`, `<page>--notifications-open.png` and the opened global confirm, signing PIN, Users reset and backups restore modals plus a toast), including the mandatory Security PIN prompt (a fixture account whose signing key was cleared). One line per page: `OK`, `OVERFLOW by N px` (past the viewport; clipped or fixed elements count, content inside a deliberately scrollable `overflow-x: auto` container such as a table does not), `CLIPPED by N px` (past its card's padding) or `SQUEEZED`. At 360 px it also checks that the menu and the notification list close each other and that the menu closes when the viewport grows past `lg`. Exit code 1 when any check fails.
- Accessibility pass (`accessibility-check.mjs`, output after the layout lines, one line per page or dialog): for each modal dialog (global confirm, signing PIN alone and on top of the Users reset and backups restore dialogs, Users reset, backups restore, scan QR on three pages, report progress, past reports, integrity progress, verify hash, average TAT, decline, guest tracking and guest QR) it opens the dialog with the keyboard (focus the real opener, press Enter; every dialog has a real opener in the fixtures, including Verify Hash, for which the launcher stores an integrity result naming one log entry), asserts the focus is inside, presses Tab and Shift+Tab at least (number of controls + 2) times and asserts the focus never leaves it and wraps in both directions, asserts the page behind is `inert` or hidden while a toast stays visible and not inert, presses Escape and asserts the dialog closed and the focus is back on the opener. For stacks it re-checks the lower dialog's trap and inert background after the top one closes, and a separate case closes the lower dialog first. The mandatory Security PIN prompt must survive Escape and a click outside, keep Log Out reachable and show a refused PIN inside itself. After an in-app navigation with a dialog (or a stack of two) open, no dialog may be open, nothing may be `inert` and Tab must move through the page; a `confirm-action` form reached by in-app navigation (Users deactivate, Clear ALL failed jobs) must ask first and send exactly one request after "Yes, Proceed" (POSTs are recorded and aborted). Two activations of Generate Report send one job request and re-open the progress; closing the guest QR dialog while the camera is still starting stops the camera (a stub stands in for the camera). On every page it checks exactly one `h1`, no skipped heading level, an accessible name with at least one letter or digit on every button and link, a label on every form control, no duplicate ids and complete `role="dialog"` markup. The Backup Manager lists one placeholder archive (`.test/backups/example-backup.zip`) so its Restore button exists, and System Overview lists one failed job. Not covered: that one Escape closes only one layer when a menu is also open (code-reviewed, not driven), screen readers, browsers other than Chromium. Exit code 1 when any line fails.
- Policy and behaviour pass (`behavior-check.mjs`, last, one line per page or feature, run at 1280 px): every page of every role is loaded by a hard load and also reached by in-app navigation (a click on its link), and the line is `OK` or lists the Content Security Policy violations (directive, blocked resource, file and line; the policy is report-only, so nothing is blocked), console errors and uncaught exceptions. The same recorder (`browser-common.mjs`) fails every line of the layout and accessibility passes too. It then checks that the features that used to be inline scripts still work, each by hard load and by in-app navigation after visiting several other pages: theme toggle, notification list, confirm forms, signing PIN prompt (freeze, Users reset with its chosen option, backups restore, decline, task completion), route builder (remove, add, a unit name with markup stays text, finalize), charts (drawn, redrawn when a filter changes), copy buttons (clipboard stubbed), pagination, staff and guest QR dialogs (camera stubbed), tracking page (adds a card, backs off after a 429 answer), the mandatory PIN prompt, the client half of the PIN cache token (an issued token is stored, a refused one cleared, a cached one skips the prompt), the PIN field of a download form being emptied, the three Users reset options reaching the request, and, with a fake clock, no poll request or Chart instance remaining after in-app navigation away from Statistics and the admin dashboard. After in-app navigation one click must cause exactly one change or request. The launcher adds 12 filler documents so the lists have more than one page.
- `playwright-core` is installed outside `package.json` on purpose; MySQL must already be running.

---

## 🔐 Default Test Accounts (Password: `password`)

| Role | Username |
|:---|:---|
| **Administrator** | `admin` |
| **Records Officer** | `records.unit` |
| **Staff: Cash Unit** | `cash.unit` |
| **Staff: Admin Unit** | `administrative.unit` |
| **Staff: Personnel** | `personnel.unit` |
| **Staff: Supply Unit** | `supply.unit` |
| **Staff: Budget Unit** | `budget.unit` |
| **Staff: Accounting** | `accounting.unit` |
| **Staff: Legal Unit** | `legal.unit` |
| **Staff: Health & Nutrition** | `health.and.nutrition` |
| **Staff: BAC Unit** | `bids.and.awards.committee.unit` |
| **Staff: SDS Office** | `schools.division.superintendent.office` |
| **Staff: ASDS Office** | `assistant.schools.division.superintendent.office` |
| **Staff: CID** | `curriculum.implementation.division` |
| **Staff: SGOD** | `school.governance.and.operations.division` |
| **Staff: ICT Unit** | `ict.unit` |

---

## ⚖️ License & Usage

**License pending.** The licensing terms for this project are being reviewed with the thesis adviser. Until a license is published, no license is granted: all rights are reserved by the authors, and the code may not be used, copied, modified or redistributed without permission.

---

Developed for the **DepEd Division of Iligan City**.
