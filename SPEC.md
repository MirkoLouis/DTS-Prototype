# SPEC: Port the fork's new features into working-php

Date: 2026-11-12. Fork: `Jevoel16/DTS-Prototype` (PR #1, fetched locally as branch `pr-1`, head `c6be12d`). Target: `working-php/` on `feat/test-suite-and-security-fixes`. Workflow: superpowers (one implementation plan per slice, executed slice by slice).

## Goal

Bring every feature and fix the fork added after the data-gathering build (`abce363`, fork commits `2b4d8a3`…`c6be12d`) into this codebase, **re-implemented in this repository's layout** (`src/Modules/<Feature>/`, `src/Common/`, PSR-4 PascalCase, layer suffixes; see `naming-conventions.md`), without merging the fork's history. Then re-run the Chapter 4–5 evidence on the result.

## Decisions (interview, 2026-11-12)

| # | Decision |
|---|---|
| D1 | Port all four groups: the 14 bug fixes, security hardening, mobile + accessibility UI, ops/performance. |
| D2 | Port the **chain seal**, keeping the signed chain-head checkpoints. Seal crypto: **HMAC-SHA256 with a new secret `APP_INTEGRITY_KEY`** in `.env` (the fork's design). |
| D3 | Guest rate limits per IP: **submissions 10 per minute**; tracking search 60/min, document card 120/min, status polling 240/min (fork's numbers). Login/PIN limiting stays `AttemptThrottleService`. |
| D4 | Tracking-form printing: **the fork's rule**: a guest can print only codes submitted in their own session; signed-in users only documents `DocumentPolicy::view` allows. |
| D5 | Default usernames stay as they are (`admin`, `records.unit`, `cash.unit`, …). |
| D6 | After all slices: **re-run the full evidence set** into `working-php/documentation/results/<date>-v2.7/` and update the manuscript items. |
| D7 | Never merge PR #1 or any fork commit: its history contains the private `from_dts/` export. Port by re-implementation only. |

## Already present here (do not port)

HMAC system signatures (we use the server Ed25519 key), login/PIN limiting (`AttemptThrottleService`), Freeze-All PIN reporting, unfreeze state fixes, officer step rule, shared verification helpers (`HashChainService`), removal of ProfileController/PHPMailer/SMTP/DocumentPolicyMiddleware, tracking codes in toasts, minified Tailwind, `APP_DEBUG`. **Rebuild Chain** was removed on purpose in v2.0.0 (re-hashing a chain defeats tamper evidence) and stays removed.

## Out of scope

Moving the app to the repository root; deleting `backup-laravel/`; Windows NGINX/php-cgi/OpenSSL support; `composer run prod` starting the worker (the systemd service owns it); new default usernames (D5); the fork's documentation files (`ARCHITECTURE.md`, `HARDWARE_SPECS.md`, `QUANTUM_SAFETY.md`); the fork's Windows concurrency script; anything under `from_dts/`.

## Global constraints

- Every schema change goes into `database.sql` **and** is appended to `scripts/migrations/master-migration.sql`, which must stay idempotent (MySQL 8 has no `ADD COLUMN IF NOT EXISTS`: guard column/index/FK changes with an `information_schema` check and a prepared statement).
- Cross-cutting concerns are shared modules with a public API (rate limiting, audit log, error pages, MySQL CLI wrapper), placed per the naming rulebook (`Common/` or the owning module).
- Log format v2 (`signedMessage`, `chainHash`, `calculateStateHash`) does not change; the seal is an additional layer.
- No shell strings built from input or config: external tools run through `proc_open` with argument arrays.
- TDD: each fix or feature starts with a failing test; the default suite stays green after every slice; the class-reference checker reports 0 unresolved.
- Each slice ends with a CHANGELOG entry and, where behaviour visible in the paper changes, a manuscript item.

## Slices

Each slice is independently verifiable and committed separately. Order is binding where noted.

### S1. Integrity-safe user lifecycle and step rule (bugs 1–2)
- Users are **deactivated, never deleted**: `users.deactivated_at TIMESTAMP NULL`; `document_logs.user_id` and `user_public_key_histories.user_id` become `ON DELETE RESTRICT`. `DELETE /users/{id}` and `POST /users/{id}/delete` deactivate. Deactivated users cannot sign in and are signed out on their next request (`AuthMiddleware`). The users list shows them as deactivated, with reactivation.
- Admins are bound by the department-step rule like officers and staff for receive/complete (`DocumentPolicy::process`); admin-only actions (freeze, unfreeze, auto-resolve) keep their own policies.
- Acceptance: deleting a user leaves every chain verifiable (integrity check 100 %); a deactivated user's login fails; an admin cannot complete another unit's step. Checks: new workflow and HTTP tests; a test that deactivating a user who signed logs keeps `IntegrityCheckJob` at 100 %.

### S2. Admin and intake correctness fixes (bugs 3–7, 14)
- "All Roles" filter returns all users; intake date filter uses the real column (`DocumentQueryService:203`); failed-job delete redirects to `/system-overview`; validation errors render as separate lines (no literal `<br>` in escaped toasts); user create/edit forms have a department field and a Records Officer must belong to the Records Unit; the admin debug modal inserts values as text (locate the sink first).
- Acceptance: one failing-then-passing test per fix (HTTP where the fix is in a page).

### S3. Small security fixes (bugs 8–10) and the print rule (D4)
- `CacheMiddleware` does not cache (or serve from cache) URLs with a query string.
- Login performs one bcrypt verification even for unknown usernames.
- XLSX reports write guest-entered text as string cells (no formula evaluation); report filters are whitelisted before being stored (no CSRF token in `report_jobs.filters`).
- Tracking-form print: guest allowed only for codes in `$_SESSION['submitted_codes']` (set on successful submission); signed-in users go through `DocumentPolicy::view`; otherwise 403 page.
- Acceptance: tests for each (cache file count unchanged after `?x=1` requests; login timing path executes `password_verify` once for an unknown user; a title `=HYPERLINK(...)` appears as text in the XLSX; stored filters contain only whitelisted keys; print 403/200 matrix).

### S4. Worker and backup reliability (bugs 11–13, retention)
- Job claim checks the claiming `UPDATE`'s `rowCount`; `Database` reconnects once on "MySQL server has gone away" (error 2006/2013) for long-lived processes; jobs reserved longer than 30 minutes move to `failed_jobs` (scheduled task in `WorkerScheduleService`).
- New `Common/Database/MysqlCli` runs `mysqldump`/`mysql` via `proc_open` argument arrays, password via the child environment, port passed; backups exclude `jobs`, `cache`, `rate_limits`, `worker_schedule`; file names include the database name; `BACKUP_KEEP` (default 14) deletes the oldest backups beyond the limit, never the newest.
- Acceptance: tests for claim race (two claimers, one wins), stale-job recovery, `MysqlCli` argument building (no shell), retention; a real backup + restore round trip on `deped_dts_test`.

### S5. Backup and seeding hardening
- Restore and delete require the admin's Security PIN (verified by signing, as in the fork). Every restore first writes a `pre-restore` backup. Restore/delete/create are recorded in a keyed, append-only `storage/logs/system-audit.log` (`Modules/System/SystemAuditService`: JSON lines `{ts, event, data, prev, mac}`, `mac` = HMAC over the previous `mac` and the entry, with `verify()`); after a restore the admin is notified how many documents changed.
- `setup-production-db.js` refuses to wipe an existing DTS database unless `--force` is passed.
- Sessions move to `storage/sessions` (created 0770), and PHP session garbage collection is enabled for that path (`session.gc_probability` > 0), since the system cron job only cleans `/var/lib/php/sessions`. Seeders clear only this folder.
- `APP_INTEGRITY_KEY` is introduced here (generated by `scripts/generate-keys.php --integrity`), shared with S9.
- Acceptance: tests for PIN-gated restore/delete, pre-restore file creation, audit-log tamper detection (`verify()` fails after editing, reordering or removing a middle line), the `--force` guard, and the session path.

### S6. Password change
- `users.must_change_password BOOLEAN`; set for seeded accounts and whenever an admin sets a password. `AuthMiddleware` redirects such users to `/account/password` (allowing only that page and logout). Self-service `GET/POST /account/password` in `Modules/Users/AccountController`: current password required, minimum length as in the fork, wrong-current-password attempts throttled.
- Acceptance: HTTP tests for the forced redirect, a successful change clearing the flag, a wrong current password, and the throttle.

### S7. Guest rate limiting and error pages (D3)
- `Common/RateLimit/RateLimitService` (fixed window, `rate_limits` table, its own DB connection so limits survive rolled-back transactions) and `Common/Middleware/RateLimitMiddleware` with route parameters `bucket,max,windowSeconds`: `submit,10,60`; `track,60,60`; `track-module,120,60`; `track-poll,240,60`. Styled 403/404/429 pages through one shared `render_error_page()` helper; 429 sends `Retry-After`. Expired `rate_limits` rows are purged by the worker.
- Acceptance: HTTP tests: the 11th submission within a minute gets 429 with `Retry-After`; limits are per IP and per bucket; the window resets.

### S8. UI: mobile and accessibility (before S9 in the fork's order; independent of the seal)
- Port the fork's responsive changes (`cf572a5`) and accessibility fixes (dialog roles and labels, focus handling and Escape for modals, names for icon-only buttons, heading order, autocomplete hints on the guest form) onto this repository's views.
- Acceptance: views render at 360 px width without horizontal scrolling on the main pages (screenshots of guest welcome/track, intake, tasks, releasing, admin dashboard, system overview); modals close on Escape and return focus; `LayoutAssetsTest` and the HTTP suite stay green.

### S9. Strict Content Security Policy and security headers
- Headers on every response: `Content-Security-Policy` (`default-src 'self'; script-src 'self'`; styles may keep `'unsafe-inline'`; `img-src 'self' data: blob:`; `media-src 'self' blob:` for the QR camera), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, no `X-Powered-By` (PHP and the nginx template).
- All inline `<script>` blocks and inline event handlers move into files under `public/js/` (as in the fork's `662a681`), with server data passed through `data-*` attributes or JSON in non-executable `<script type="application/json">` blocks.
- Acceptance: an HTTP test asserts the headers; a test fails if any rendered page contains an inline script or `on*=` attribute; manual browser check that the camera QR scan, PJAX navigation, signing modal, charts and toasts still work with no CSP violations in the console.

### S10. Chain seal (D2), after S1 and S5
- `document_logs.seal CHAR(64) NULL` = HMAC(`APP_INTEGRITY_KEY`, block hash) for every new entry. `documents.chain_seal CHAR(64) NULL` = HMAC over `[id, status, current_step, current_department_id, decline_reason, released_by_user_id, last log id, last block hash, log count]`, refreshed by `HashChainService::createLog` inside the same transaction, **except** for the automatic freeze, which keeps the mismatch flagged until an admin unfreezes or auto-resolves (both re-seal).
- `PreActionIntegrityService::passes` additionally requires a valid seal on the newest entry and a matching chain seal (closes the gap that `status` and `current_step` were not covered). `IntegrityCheckJob` verifies every entry's seal and every document's chain seal and reports them separately.
- Safe recovery: auto-resolve restores only from a snapshot whose log entry verifies (seal, block hash, state hash); unfreeze restores step and holder from that verified snapshot.
- `scripts/seal-chains.php` seals existing data once (documented as trust-on-first-use in the README and manuscript); without `APP_INTEGRITY_KEY` the app refuses to start the worker and the integrity check reports "seal not configured" (no silent pass).
- Acceptance: tamper-matrix rows added and passing: editing `status`, editing `current_step`, deleting the newest log entry, and appending an entry without the key are now caught **by the pre-action check**; the privileged-insider row (attacker with the key) is documented as still undetected by the seal and detected only by an off-machine checkpoint. Benchmark rows `chain_seal_refresh` and `pre_action_integrity_check` re-measured.

### S11. Ops and performance
- Indexes: `cache.expiration`, `jobs (reserved_at, available_at)`, `notifications (department_id, is_read, created_at)`, `departments.name` (unique), `purposes.name`, `documents.updated_at`. Drop unused Laravel tables (`job_batches`, `password_reset_tokens`, `sessions`, `cache_locks`, `prediction_keywords`) after confirming no code uses them. `database.sql` no longer hard-codes `deped_dts` (the seeder creates and selects `DB_DATABASE`). Hourly purge of expired `cache` rows.
- Metrics backfill rebuilds only the last 2 days every 5 minutes (`--full` for seeders) instead of truncating every 30 minutes.
- Tracking-form PDF embeds a downsized seal image (fork: 568 KB → 101 KB, ~0.86 s → ~0.44 s).
- Acceptance: migration runs twice without error on a copy of `deped_dts`; `EXPLAIN` shows the new indexes used by the notification poll and the job claim; backfill test covers a 2-day window and `--full`; PDF size and time measured before/after.

### S12. Evidence re-run and paper alignment (D6), last
- Full suite; `composer test:security` × 5; `CONCURRENCY_RUNS=5 composer test:concurrency`; `BENCH_DOCS=100000 BENCH_ITERATIONS=1000 composer bench`; `composer replay` (must still be 57.91 % / 66.85 %, since routing is untouched). Results into `working-php/documentation/results/<date>-v2.7/` with a README index like v2.5.2.
- Manuscript items: the chain seal (it now exists: revise M7/M39/M40, the "Chain Seal" glossary entry becomes accurate, and the 1.6 Process paragraph can keep a corrected seal sentence), the pre-action check scope (M8 resolved), user deactivation, rate limits, CSP, and the evaluation-build note (M38).

## Interfaces introduced

| Name | Location | Public API |
|---|---|---|
| `RateLimitService` | `src/Common/RateLimit/` | `hit(string $key, int $max, int $windowSeconds): bool`, `tooMany`, `availableIn`, `clear`, `clientIp()` |
| `RateLimitMiddleware` | `src/Common/Middleware/` | route middleware `RateLimitMiddleware::class . ':bucket,max,window'` |
| `render_error_page(int $status, string $title, string $message)` | `src/Common/Utils/helpers.php` | renders the styled page and exits |
| `MysqlCli` | `src/Common/Database/` | `dump(string $file, array $excludeTables): void`, `restore(string $file): void` |
| `SystemAuditService` | `src/Modules/System/` | `record(string $event, array $data = []): void`, `verify(): bool` |
| `AccountController` | `src/Modules/Users/` | `showPasswordForm()`, `updatePassword()` |
| `HashChainService` | existing | `sealFor(string $blockHash): ?string`, `chainSealFor(array $document, int $lastLogId, string $lastHash, int $count): ?string`, `refreshChainSeal(int $documentId): void`, `chainSealMatches(...)` |
| `.env` keys | `.env.example` | `APP_INTEGRITY_KEY`, `BACKUP_KEEP` |

## End-to-end verification

1. After each slice: `vendor/bin/phpunit` green, class-reference checker 0 unresolved, slice-specific tests shown failing first.
2. After S10: `composer test:security` passes including the new seal rows; `php scripts/seal-chains.php` on a re-seeded `deped_dts` (100,000 documents), then an integrity check from System Overview reports 100 % with seals verified.
3. Manual (user): the S8 screenshots at 360 px; the S9 browser check with the console open; a backup → restore → audit-log verify round trip; a guest submitting 11 times in a minute.
4. S12 evidence folder committed; `spec-reviewer` run on the full diff against this SPEC before the branch is called done.

## Preconditions (user, outside the code)

- Ask the fork's owner to make `Jevoel16/DTS-Prototype` private or delete it (it publishes `from_dts/`).
- Close PR #1 without merging, and ask GitHub Support to purge `refs/pull/1/head` and cached views of the old commits.
- The local branch `pr-1` is for reading the fork's code during the port; delete it afterwards.
