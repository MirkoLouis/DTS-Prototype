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
   composer run seed:prod
   ```

5. **Launch Local Server & Background Worker:**
   ```bash
   # Starts the Web Server, PostCSS Watcher, and Async Queue Worker seamlessly
   composer run dev
   ```

---

## 🌱 Database Seeding Commands (`working-php/scripts/seeders`)

- **Production Setup (`composer run seed:prod` / `node scripts/seeders/setup-production-db.js`):** Builds database tables from `database.sql` and seeds 15 DepEd departments (including the ICT Unit), baseline document purposes, and initial user accounts.
- **High-Speed Direct Database Seeder (`composer run seed:fast [docs]` / `php scripts/seeders/fast-seed.php`):** Generates up to 50,000+ document logs directly in MySQL in seconds by pre-decrypting Ed25519 keys into RAM.
- **Slow-Speed API Seeder (`composer run seed:dev [docs] [chunk] [concurrency]` / `node scripts/seeders/seed.js`):** Dispatches live HTTP requests against the web server to simulate multi-user traffic and session handling, also functions as a concurrency test.

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
| Handled by the app | Page cache (purged hourly), `database_metrics` (rolled up to hourly after 24 h), notifications (newest 10 per user/department), `navigation.log` (renamed at 50 MB), backups older than `BACKUP_KEEP_DAYS`, jobs whose process died (recorded under Failed Jobs) | The worker's scheduler (`console.php`) |
| Handled by the operating system | nginx logs (`/var/log/nginx/dts_*.log`), PHP-FPM log (`/var/log/php<version>-fpm.log`), PHP session files (`/var/lib/php/sessions`), `storage/logs/worker.log` | logrotate and the PHP session cleaner, which must actually run (below) |
| Kept forever | `documents`, `document_logs`, `user_public_key_histories` (the audit trail) and the signed checkpoints in `storage/checkpoints/` | Nobody: plan disk capacity instead (below) |

### 1. Keep the queue worker running

The worker runs the scheduler (daily checkpoint, metrics, cache cleanup, stale-document cleanup) and the background jobs. Its last-run times are stored in the `worker_schedule` table, so a restart never postpones a daily task. It still has to be restarted when it exits. `composer run dev` does this itself (a watchdog relaunches it within about 5 seconds). On a server, run it as a systemd service, which also starts it at boot:

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

### 2. Make sure the operating system rotates logs and cleans PHP sessions

Ubuntu and Debian already install the rules: `/etc/logrotate.d/nginx` (daily, keep 14, compressed), `/etc/logrotate.d/php<version>-fpm` (weekly, keep 12), and a PHP session cleaner (`/etc/cron.d/php` and `phpsessionclean.timer`; `php.ini` sets `session.gc_probability = 0`, so PHP itself never deletes old session files). The rules only help if logrotate is installed and something runs it. Minimal images (containers, Distrobox) often lack the `logrotate` package itself:

```bash
command -v logrotate || sudo apt install logrotate     # installs logrotate.timer and /etc/cron.daily/logrotate
```

**On a machine with systemd** (a normal server or desktop install), check that both timers are active:

```bash
systemctl list-timers logrotate.timer phpsessionclean.timer
sudo systemctl enable --now logrotate.timer phpsessionclean.timer    # if either is missing or inactive
```

**On a machine without systemd as PID 1** (Docker, Distrobox, WSL), timers never fire. Install and start cron, which runs `/etc/cron.daily/logrotate` and `/etc/cron.d/php`:

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

The audit trail and the checkpoints are never deleted, because removing either would weaken tamper detection. Each daily checkpoint lists every document's chain head, so it grows with the number of documents: about **8.5 MB per day at 100,000 documents** (about 3 GB per year), and about 25 MB per day at 300,000 documents. Copy checkpoints off the server regularly (USB, another machine), as the admin manual describes. That copy is what lets you detect deletions even if the server itself is compromised. Backups in `storage/app/backups/` expire: each new backup deletes those older than `BACKUP_KEEP_DAYS` (default 14 days), always keeping the newest; set it to `0` to keep every backup.

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

## 🧪 Testing (`working-php`)

**Setup.**

```bash
cd working-php
cp .env.testing.example .env.testing
APP_ENV=testing php scripts/generate-keys.php --system   # writes the system key into .env.testing
```

You do not create the database by hand: `TestLifecycle` (called from `tests/bootstrap.php`) drops and recreates `deped_dts_test` and the `working-php/.test/` folder before every run, and removes them again after it. Every file a test creates lives in `.test/` (gitignored): the test response cache, checkpoints, integrity result, backups, navigation log and temporary files (`Tests\Support\TestFiles`). Result CSVs are kept. Only `.env.testing` and the system key are one-time setup. Within a run, the suite resets `deped_dts_test` from `database.sql` before every test, and the security tests deliberately corrupt and overwrite data. It therefore refuses to run unless `APP_ENV=testing` and the database name ends in `_test`. The test environment also uses its own response cache, checkpoint directory and integrity-result file so it never touches real data.

**Commands** (from `working-php/`).

| Command | What it runs |
| :--- | :--- |
| `composer test` | Unit, workflow and HTTP suites only. |
| `vendor/bin/phpunit` | Default suites: unit, workflow, HTTP and security (concurrency and benchmark are excluded by group). |
| `composer test:security` | Tamper-injection tactics and HTTP attack tests, tagged by threat actor. |
| `composer test:concurrency` | Kiosk-burst and simultaneous-action tests against `php -S` started with 16 workers (`PHP_CLI_SERVER_WORKERS=16`); set `TEST_BASE_URL` to target an nginx/FPM deployment instead. The parallelism check is timing-sensitive and can fail on a heavily loaded machine. |
| `composer test:workflow` | Thesis: document workflow from submission and intake to receive, complete and release (`LifecycleTest`), with the department-step rules for officers and admins (`OfficerStepRuleTest`, `AdminStepRuleTest`). PHPUnit group `thesis-workflow`. |
| `composer test:integrity` | Thesis: hash-chain integrity: the tamper matrix and the 100 % control (`TamperInjectionTest`), signature verification (`SignatureVerificationTest`) and signer protection (`UserLifecycleIntegrityTest`). PHPUnit group `thesis-integrity`. |
| `composer run evidence` | Thesis evidence set in one folder: all default suites (unit, workflow, HTTP, security) ×1, `test:workflow` ×1, `test:integrity` ×`EVIDENCE_RUNS`, and the concurrency suite once with `CONCURRENCY_RUNS=EVIDENCE_RUNS` (default 5). Every run still executes when one fails; the command exits 1 if any run failed. `composer run evidence -- --list` prints the commands without running them. |
| `composer bench` | Performance benchmark. Seeds through `scripts/seeders/fast-seed.php` (the `seed:fast` logic, direct DB inserts); 100,000 documents took 83.8 s in a logged run, so 1,000,000 takes about 14 minutes if it scales linearly. Defaults to 1,000,000 documents and 1,000 iterations; full run: `BENCH_DOCS=1000000 BENCH_ITERATIONS=1000 composer bench`. Smaller runs: e.g. `BENCH_DOCS=10000`. Set `BENCH_HTTP=0` to skip HTTP timings. |
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
| `test:login-rate-limit` | The third failed login times out the account; a correct password is refused while locked. |
| `test:pin-rate-limit` | The third wrong Security PIN times out signing; a cached token does not count as a failure. |
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
| `test:backup-pin` | Restoring or deleting a backup needs the admin's Security PIN; wrong PINs lock out; backup actions are recorded in the audit log. |
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
| `test:test-guard` | Tests refuse to run outside APP_ENV=testing or against a non-_test database. |
| `test:test-lifecycle` | The per-run test database and artifact lifecycle. |
| `test:test-env-probe` | The /__test/env probe reports the testing environment and database. |
| `test:http-client-probe` | The test HTTP client refuses servers that are not running the testing environment. |
| `test:composer-scripts` | Every test class has a composer script with a description, listed in the README. |

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

**Known limitation: the fully privileged insider.** The audit trail is tamper-evident, not tamper-proof. Anyone without the users' PINs and the server signing key cannot alter, delete or reorder log entries undetected. However, the tamper matrix shows that someone with database access can register their own key pair in `user_public_key_histories` and append a properly signed entry, and nothing detects it, because public keys are trusted from the same database. Someone who also controls the server holds the server signing key and can re-sign entries and checkpoints. No mechanism stored on the same machine can detect such an insider. The defence is organizational: separation of duties between server and database administration, restricted and logged administrator access, and copying the daily signed checkpoint (`storage/checkpoints/`) off the server. Certifying user public keys with an offline key is future work.

**Migration notice.** Existing databases must run `working-php/scripts/migrations/master-migration.sql` to enable login and PIN attempt limiting (3 failures within 1 minute trigger a 1-minute timeout). Until it is run, limiting is off and a line is written to `error_log` (fail-open). Fresh databases built from `database.sql` already include the table.

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
- Do not run it in a shell where `APP_ENV=testing` is exported; it refuses.
- Titles are tagged `CONC-LIVE-<run_id>-<i>`. The script always auto-deletes only its own documents, and prints the run id and the deleted count. "Its own" means rows titled with its run id prefix `CONC-LIVE-<run_id>-…` that were created during the run (id above the highest id seen at start). The run id is `LIVE` plus a timestamp to the second (`YmdHis`), so two runs started in the same second share a run id and each would delete the other's rows. If anything remains, it prints a manual `DELETE` statement.
- It warns if a checkpoint was written during the run, because those chain heads will show as missing in the next audit.
- Results are appended to `storage/test-results/<run_id>/live-concurrency.csv` (each script run gets its own folder).

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
