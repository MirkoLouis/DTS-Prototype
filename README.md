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
| `composer run dev` | Runs [scripts/dev.php](working-php/scripts/dev.php) to start local dev server & Tailwind watcher. |
| `composer run corrupt` | Runs [scripts/corrupt.php](working-php/scripts/corrupt.php) to simulate database tampering and test the JIT pre-action integrity check and automatic freezing. |
| `php scripts/tune-database.php` | Analyzes system memory and outputs optimal MySQL `my.cnf` buffer pool configurations. |
| `php scripts/generate-keys.php` | CLI tool for generating missing Ed25519 digital signature keypairs. `--system` creates the server key used to sign system actions (`SYSTEM_SIGNING_SECRET` / `SYSTEM_SIGNING_PUBLIC` in `.env`). |
| `php scripts/create-checkpoint.php` | Writes a signed chain-head checkpoint to `storage/checkpoints/` (the worker also does this daily). Copy checkpoints off the server. |
| `composer run test` / `test:security` / `test:concurrency` | PHPUnit suites, see [Testing](#-testing-working-php). |
| `composer run bench` | Performance benchmark (state/chain hashing, log reads, pre-action integrity check, Argon2id, AES unwrap, Ed25519, HTTP timings); writes `benchmark.csv` into the run folder under `storage/test-results/`. Replaces the former `benchmark-crypto.php`. |
| `composer run replay -- <csv>` | Runs [scripts/route-replay.php](working-php/scripts/route-replay.php) to replay the legacy routing export against the route suggester. |
| `scripts/migrations/master-migration.sql` | The single, idempotent migration for existing databases; new schema changes are appended to it (see the migration notice under Testing). |

---

## 🧪 Testing (`working-php`)

**Setup.**

```bash
cd working-php
cp .env.testing.example .env.testing
APP_ENV=testing php scripts/generate-keys.php --system   # writes the system key into .env.testing
```

You do not create the database by hand: `TestLifecycle` (called from `tests/bootstrap.php`) drops and recreates `deped_dts_test` and the `-test` response cache, checkpoint directory and integrity-result file before every run, and removes them again after it. Result CSVs are kept. Only `.env.testing` and the system key are one-time setup. Within a run, the suite resets `deped_dts_test` from `database.sql` before every test, and the security tests deliberately corrupt and overwrite data. It therefore refuses to run unless `APP_ENV=testing` and the database name ends in `_test`. The test environment also uses its own response cache, checkpoint directory and integrity-result file so it never touches real data.

**Commands** (from `working-php/`).

| Command | What it runs |
| :--- | :--- |
| `composer test` | Unit, workflow and HTTP suites only. |
| `vendor/bin/phpunit` | Default suites: unit, workflow, HTTP and security (concurrency and benchmark are excluded by group). |
| `composer test:security` | Tamper-injection tactics and HTTP attack tests, tagged by threat actor. |
| `composer test:concurrency` | Kiosk-burst and simultaneous-action tests against `php -S` started with 16 workers (`PHP_CLI_SERVER_WORKERS=16`); set `TEST_BASE_URL` to target an nginx/FPM deployment instead. The parallelism check is timing-sensitive and can fail on a heavily loaded machine. |
| `composer bench` | Performance benchmark. Seeds through `scripts/seeders/fast-seed.php` (the `seed:fast` logic, direct DB inserts); 100,000 documents took 83.8 s in a logged run, so 1,000,000 takes about 14 minutes if it scales linearly. Defaults to 1,000,000 documents and 1,000 iterations; full run: `BENCH_DOCS=1000000 BENCH_ITERATIONS=1000 composer bench`. Smaller runs: e.g. `BENCH_DOCS=10000`. Set `BENCH_HTTP=0` to skip HTTP timings. |
| `composer replay -- <path-to-legacy-csv>` | Replays the legacy routing export (`dts_docroutes` CSV) through `HeuristicRoutingService` (`src/Modules/Routing/HeuristicRoutingService.php`); optional second argument is the output directory, and `--records-names="A\|B"` overrides the Records intake section names. Besides the heuristic it scores the baselines `none`, `frequent_so_far` and `static_hindsight` (look-ahead, hindsight) into `route-replay-baselines.csv`, and writes `route-replay-purpose-profile.csv`. |

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

**Seeders.** `fast-seed.php` and `import-legacy.php` rely on the database unique key for tracking codes and retry the chunk on MySQL duplicate error 1062; there is no in-memory duplicate check. Under `APP_ENV=testing`, `fast-seed.php` only clears the `-test` cache and leaves PHP sessions alone.

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
