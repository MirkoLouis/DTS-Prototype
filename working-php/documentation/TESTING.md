<a id="top"></a>

# Testing

Automated suites, thesis evidence runs and the manual browser checks. The machine the recorded results came from is in [TEST_ENVIRONMENT.md](TEST_ENVIRONMENT.md).

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
| `test:refusal-message` | A refused workflow action shows its reason on the page (wrong department, another unit's step, a document just frozen as tampered) and changes nothing else. |
| `test:document-denial-service` | Every refusal reason: administrator-only and officer roles, frozen documents, and the department-step reason for each document status. |

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
| `test:integrity-key` | The shared integrity-key helper accepts only base64 of exactly 32 bytes; the test override is ignored outside the testing environment. |
| `test:pin-cache-duration` | The Security PIN cache lasts four hours by default: one constant, used by the config fallback, the PIN cache and .env.example. |
| `test:password-hasher` | PasswordHasher is the one place passwords are hashed: Argon2id, with older bcrypt hashes still verifiable and flagged for rehash. |
| `test:credential-policy` | CredentialPolicy holds the credential rules: separate password and PIN minimum lengths and the common-password blocklist. |
| `test:chain-seal` | Entry seals and chain seals: the sealed fields and their normalization, every workflow action leaves valid seals, the automatic freeze keeps the mismatch, unfreeze and auto-resolve re-seal, unfreeze refuses changed content, recovery never rolls back (it refuses when the newest entry fails), unsealed documents are refused and not frozen. |
| `test:seal-chains-script` | scripts/seal-chains.php seals existing data once after verifying each chain (refusing chains that do not verify), never seals a missing seal on an already sealed database or overwrites a seal without an explicit flag and the database name, and refuses without a key or against another database. |
| `test:missing-integrity-key` | Without APP_INTEGRITY_KEY the worker does not start, the integrity check reports "seal not configured", and the pre-action check refuses the action without freezing. |

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
| `test:security-headers` | Every PHP response (page, JSON, redirect, error page, download) carries the enforced Content Security Policy, X-Frame-Options, X-Content-Type-Options and Referrer-Policy exactly once, and no X-Powered-By, report-only policy or HSTS. |
| `test:content-type` | Every route answers with a Content-Type that matches its body (JSON, HTML in UTF-8, PDF, archive, spreadsheet, CSV), downloads carry a Content-Disposition. |
| `test:json-data-block` | The JSON data block helper escapes script and comment endings, quotes and ampersands, and refuses unsafe element ids. |
| `test:tracking-form-print` | Print-tracking-form links on officer pages, none for staff; the slip PDF is served only to the submitting session or to staff allowed to view the document. |
| `test:cache-query-string` | The response cache stores bare URLs only; URLs with a query string create no cache files. |
| `test:login-timing` | An unknown username takes about as long to refuse as a wrong password (one Argon2id check either way). |
| `test:password-rehash` | Sign-in verifies with Argon2id, upgrades a legacy bcrypt hash once on success, and fixture accounts use Argon2id. |
| `test:released-report-service` | Report rows are text cells only (no formula injection); report filters are whitelisted. |
| `test:report-filters` | Stored report filters carry no CSRF token; a formula-like title is downloaded as text. |
| `test:report-ownership` | A report job is downloaded, inspected and cancelled only by the user who requested it. |
| `test:verify-hash-label` | System Overview uses the "Verify Hash" label. |
| `test:ict-unit` | The production seeder includes the ICT Unit, and intake can route to it. |
| `test:debug-logging` | Requests do not write the temporary router log; the navigation log still records them. |

*Operations and data*

| Command | What it checks |
| :--- | :--- |
| `test:master-migration` | The master migration upgrades a pre-deactivation schema and the schema before S11 (adds the indexes, drops the unused tables, reports duplicate department names instead of failing), is idempotent, and ends with the same schema as a fresh database.sql. |
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
| `test:nginx-error-page` | The static gateway error page exists, is self-contained, satisfies the Content Security Policy, and every NGINX config routes 502, 503 and 504 to it (live NGINX not exercised). |
| `test:nginx-security-headers` | The NGINX template adds the same headers once (policy text generated from the PHP constant), hides PHP's copy, sets server_tokens off, passes nginx -t and, run as a temporary instance, answers static files and the gateway page with the headers. |
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
| `test:seed-guard` | The production seeder's guard refuses a database that holds documents or users unless --force is passed (it checks DB_DATABASE only; database.sql names no database), the seeder creates and selects DB_DATABASE, and the API seeder stops with a clear message on HTTP 429. |
| `test:database-schema-file` | `database.sql` holds no DROP, TRUNCATE or DELETE; run again on a database with data (mysql client and SqlScriptRunner) it fails with "already exists" and changes no row or table. |
| `test:session-storage` | Sessions are stored in the app's own folder, cleaned up by the app, with a 12-hour idle limit and hardened cookies. |
| `test:test-guard` | Tests refuse to run outside APP_ENV=testing or against a non-_test database. |
| `test:test-lifecycle` | The per-run test database and artifact lifecycle. |
| `test:test-env-probe` | The /__test/env probe reports the testing environment and database. |
| `test:http-client-probe` | The test HTTP client refuses servers that are not running the testing environment. |
| `test:composer-scripts` | Every test class has a composer script with a description, listed in this file (`documentation/TESTING.md`). |
| `test:rate-limit-service` | Rate limiting: fixed window per bucket and client, window reset, independent buckets and clients, a hit survives a rollback of the main connection, purge of expired rows, hashed keys, fail open. |
| `test:rate-limit-middleware` | The rate-limit middleware: the RATE_LIMIT_ENABLED switch, the client taken from REMOTE_ADDR only, and the five route limits defined once with the limiter first. |
| `test:rate-limit-http` | Per-IP limits on guest submission, tracking, the tracking API and the tracking-form print: 429 page or JSON with Retry-After, independent buckets, recovery after the window, fail open without the table. |
| `test:rate-limit-purge-task` | The hourly worker task that deletes expired rate_limits rows. |
| `test:sql-script-runner` | The SQL script runner returns the notices a script raises (the migration's duplicate-department notice), drops other result rows, and still stops on a failing statement. |
| `test:index-usage` | EXPLAIN proves the S11 indexes are used by the notification poll, the job claim, the cache purge and the department and purpose name lookups (on tables of 20,000 rows), the poll and the claim return the right rows, and the legacy import merges department names that differ only in case and logs it. |
| `test:metrics-backfill` | Department metrics backfill: the 2-day window rebuilds only recent days (Asia/Manila day boundaries), --full rebuilds everything, repeated runs give identical rows, and a failed rebuild keeps the previous rows; only the metrics cache entries are cleared, and only when rows changed; the scheduler runs it every 5 minutes. |
| `test:cache-purge` | The hourly worker task that deletes expired rows of the cache table (driven through the scheduler with a fake clock), and Cache::forgetPrefix. |
| `test:tracking-slip-pdf` | The tracking slip embeds the 375 px print copy of the DepEd seal (300 dpi at the printed size) and the PDF stays under 150 KB. |

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

**Known limitation: the fully privileged insider.** The audit trail is tamper-evident, not tamper-proof. Anyone without the users' PINs and the server signing key cannot alter, delete or reorder log entries undetected. However, the tamper matrix shows that someone with database access can register their own key pair in `user_public_key_histories` and append a properly signed entry; public keys are trusted from the same database, so the signature alone cannot tell. Since version 2.7.12 such an entry is still caught, because it lacks a valid entry seal unless the insider also holds `APP_INTEGRITY_KEY`. Someone who also controls the server holds the server signing key and can re-sign entries and checkpoints. No mechanism stored on the same machine can detect such an insider; this includes the entry and chain seals ([Chain Seal](CHAIN_SEAL.md)), which someone holding `APP_INTEGRITY_KEY` can recompute. The defence is organizational and is set out in [Vendor-Recommended Security Baselines](SECURITY_BASELINES.md), section 5. Certifying user public keys with an offline key is future work.

**Migration notice.** Existing databases must run `working-php/scripts/migrations/master-migration.sql`; what it enables is listed in [Vendor-Recommended Security Baselines](SECURITY_BASELINES.md), section 2. Fresh databases built from `database.sql` already include everything in it.

**Fixed findings.** F1 (finalizing a route accepted an unknown department), F2 (staff could view documents outside their route through `DocumentController::show`) and F3 (`CacheMiddleware` wrote cached pages non-atomically, so a cold-cache kiosk burst could get pages without a CSRF token) are fixed. The former finding tests remain as regression tests, and the default `vendor/bin/phpunit` ends with 0 failures.

**Workflow rules.** Officers are bound by the department-step rule like staff: acting on a document outside their step is refused, and the page says where the document is waiting or who is processing it (`DocumentDenialService`). They keep intake, the final Records receive, and release.

**Seeders.** `fast-seed.php` and `import-legacy.php` rely on the database unique key for tracking codes and retry the chunk on MySQL duplicate error 1062; there is no in-memory duplicate check. Under `APP_ENV=testing`, `fast-seed.php` only clears the test cache in `.test/` and leaves PHP sessions alone.

## Manual tests (you start these)

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
- Policy and behaviour pass (`behavior-check.mjs`, last, one line per page or feature, run at 1280 px): every page of every role is loaded by a hard load and also reached by in-app navigation (a click on its link), and the line is `OK` or lists the Content Security Policy violations (directive, blocked resource, file and line; the policy is enforced, so a blocked resource also breaks behaviour), console errors and uncaught exceptions. The same recorder (`browser-common.mjs`) fails every line of the layout and accessibility passes too. It then checks that the features that used to be inline scripts still work, each by hard load and by in-app navigation after visiting several other pages: theme toggle, notification list, confirm forms, signing PIN prompt (freeze, Users reset with its chosen option, backups restore, decline, task completion), route builder (remove, add, a unit name with markup stays text, finalize), charts (drawn, redrawn when a filter changes), copy buttons (clipboard stubbed), pagination, staff and guest QR dialogs (camera stubbed), tracking page (adds a card, backs off after a 429 answer), the mandatory PIN prompt, the client half of the PIN cache token (an issued token is stored, a refused one cleared, a cached one skips the prompt), the PIN field of a download form being emptied, the three Users reset options reaching the request, and, with a fake clock, no poll request or Chart instance remaining after in-app navigation away from Statistics and the admin dashboard. After in-app navigation one click must cause exactly one change or request. The launcher adds 12 filler documents so the lists have more than one page.
- Enforcement lines (last lines of `behavior-check.mjs`): the security headers as the browser receives them; an inline script, an inline event handler, a `javascript:` URL and code from a string (the `eval` path, via `setTimeout`) injected into a loaded page must not run and must be reported as an enforced violation (these deliberate violations are the only ones excluded from the failure count); a page of the app loaded in an `iframe` of another origin must be refused.
- `playwright-core` is installed outside `package.json` on purpose; MySQL must already be running.
