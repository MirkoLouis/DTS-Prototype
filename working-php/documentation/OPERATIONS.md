<a id="top"></a>

# Operations

Seeding, the scripts in `working-php/scripts`, and keeping the system running on a Linux server.

## Database Seeding Commands

- **Production Setup (`composer run seed:prod` / `node scripts/seeders/setup-production-db.js`):** Creates the database named by `DB_DATABASE` in `.env` (utf8mb4) if it is missing, builds the tables from `database.sql` in it (the file names no database and drops nothing; with `--force` the seeder first drops only this app's tables, never the database, and refuses a system schema, or a database with other tables unless `--force`), and seeds 15 DepEd departments (including the ICT Unit), baseline document purposes, and initial user accounts.
- **High-Speed Direct Database Seeder (`composer run seed:fast [docs]` / `php scripts/seeders/fast-seed.php`):** Generates up to 50,000+ document logs directly in MySQL in seconds by pre-decrypting Ed25519 keys into RAM.
- **Slow-Speed API Seeder (`composer run seed:dev [docs] [chunk] [concurrency]` / `node scripts/seeders/seed.js`):** Dispatches live HTTP requests against the web server to simulate multi-user traffic and session handling, also functions as a concurrency test. **Set `RATE_LIMIT_ENABLED=false` in the server's `.env` (and restart PHP-FPM) while it runs, and back to `true` afterwards:** it submits thousands of documents from one IP, and the public routes allow only 10 submissions per IP per minute. Without the switch the seeder stops at the first HTTP 429 with a message saying so. `seed:prod` and `seed:fast` write to the database directly and are not limited.


## Script Directory Guide

| Command / Script | Purpose |
| :--- | :--- |
| `composer run worker` | Runs [console.php](../console.php) background job processor and telemetry scheduler. |
| `composer run dev` | Runs [scripts/dev.php](../scripts/dev.php) to start local dev server & Tailwind watcher, plus the queue worker with a watchdog that restarts it if it exits (Ctrl+C stops it too). |
| `composer run prod` | Runs [scripts/prod.php](../scripts/prod.php) for a server: restarts PHP-FPM (flushes OPcache) and nginx, then keeps running until Ctrl+C. It does **not** clear `cache/` and does **not** start the queue worker; run the worker as a service (see [Running Continuously on Linux](#running-continuously-on-linux)). |
| `composer run corrupt` | Runs [scripts/corrupt.php](../scripts/corrupt.php) to simulate database tampering and test the JIT pre-action integrity check and automatic freezing. |
| `php scripts/seal-chains.php` (`composer run seal-chains`) | Seals existing data once (entry seals and chain seals) after the master migration; trusts what the database holds at that moment, so run the integrity check first. Idempotent, never overwrites an existing seal (see [Chain Seal](CHAIN_SEAL.md)). |
| `php scripts/tune-database.php` | Analyzes system memory and outputs optimal MySQL `my.cnf` buffer pool configurations. |
| `php scripts/generate-keys.php` | CLI tool for generating missing Ed25519 digital signature keypairs. `--system` creates the server key used to sign system actions (`SYSTEM_SIGNING_SECRET` / `SYSTEM_SIGNING_PUBLIC` in `.env`). |
| `php scripts/create-checkpoint.php` | Writes a signed chain-head checkpoint to `storage/checkpoints/` (the worker also does this daily). Copy checkpoints off the server. |
| `composer run test` / `test:security` / `test:concurrency` / `test:workflow` / `test:integrity` / `test:<name>` | PHPUnit suites, thesis groups and single test classes, see [Testing](TESTING.md). |
| `composer run evidence` | Thesis evidence set in one results folder, see [Testing](TESTING.md). |
| `composer run bench` | Performance benchmark (state/chain hashing, log reads, pre-action integrity check, Argon2id, AES unwrap, Ed25519, HTTP timings); writes `benchmark.csv` into the run folder under `storage/test-results/`. Replaces the former `benchmark-crypto.php`. |
| `composer run replay -- <csv>` | Runs [scripts/route-replay.php](../scripts/route-replay.php) to replay the legacy routing export against the route suggester. |
| `scripts/migrations/master-migration.sql` | The single, idempotent migration for existing databases; new schema changes are appended to it (see the migration notice under Testing). |


## Running Continuously on Linux

The DTS is meant to run without stopping. Everything that grows over time falls into one of three groups:

| Group | What | Who keeps it bounded |
| :--- | :--- | :--- |
| Handled by the app | Page cache (purged hourly), expired `cache` table rows (purged hourly), `database_metrics` (rolled up to hourly after 24 h), notifications (newest 10 per user/department), `navigation.log` (renamed at 50 MB), session files in `storage/sessions` (a session idle longer than `SESSION_LIFETIME`, 12 hours by default, is ended on its next request and its file removed by PHP's cleanup), backups older than `BACKUP_KEEP_DAYS`, jobs whose process died (recorded under Failed Jobs) | The worker's scheduler (`console.php`) |
| Handled by the operating system | nginx logs (`/var/log/nginx/dts_*.log`), PHP-FPM log (`/var/log/php<version>-fpm.log`), `storage/logs/worker.log` | logrotate, which must actually run (below) |
| Kept forever | `documents`, `document_logs`, `user_public_key_histories` (the audit trail) and the signed checkpoints in `storage/checkpoints/` | Nobody: plan disk capacity instead (below) |

### 1. Keep the queue worker running

The worker runs the scheduler (daily checkpoint, metrics, cache cleanup, stale-document cleanup, hourly purge of expired rate-limit counters and expired `cache` rows) and the background jobs. The department metrics behind the dashboard charts are rebuilt every 5 minutes for the last two days only (yesterday and today, Asia/Manila); `php scripts/backfill-metrics.php --full` rebuilds every day and is what the seeders run. The rebuild deletes and re-inserts those days in one transaction, so the charts never show an empty table. Its last-run times are stored in the `worker_schedule` table, so a restart never postpones a daily task. It still has to be restarted when it exits. `composer run dev` does this itself (a watchdog relaunches it within about 5 seconds). On a server, run it as a systemd service, which also starts it at boot:

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

**Secrets, passwords and the other security settings** are in [Vendor-Recommended Security Baselines](SECURITY_BASELINES.md).

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

The audit trail and the checkpoints are never deleted, because removing either would weaken tamper detection. Each daily checkpoint lists every document's chain head, so it grows with the number of documents: about **8.5 MB per day at 100,000 documents** (about 3 GB per year), and about 25 MB per day at 300,000 documents. Copy them off the server regularly ([Vendor-Recommended Security Baselines](SECURITY_BASELINES.md), section 5). Backups in `storage/app/backups/` expire: each new backup deletes those older than `BACKUP_KEEP_DAYS` (default 14 days), always keeping the newest; set it to `0` to keep every backup.

### 4. Update a running server

`composer run prod` runs until the next update. To install one:

```bash
cd /opt/dts/working-php
sudo systemctl stop dts-worker.service      # once the jobs table is empty: queued jobs store their class names
git pull
composer install --no-dev                   # regenerates vendor/autoload (required after files move)
npm install && npm run build                # only if CSS or Tailwind changed
mysql -u <user> -p <your DB_DATABASE> < scripts/migrations/master-migration.sql   # safe to run every time; adds what is missing
# never run database.sql here: it builds a new empty database only
# stop the running `composer run prod` (Ctrl+C), then start it again so PHP-FPM reloads the new code
composer run prod
sudo systemctl start dts-worker.service
```

Check the CHANGELOG's "Upgrade note" for the version you are installing. An update does not clear `cache/`, so the last integrity result and the signed checkpoints are kept. Run an integrity check from System Overview afterwards. Updating to 2.7.13 or later: take a backup first, then run the master migration. It adds indexes (`notifications (department_id, is_read, created_at)` for the unread poll, `jobs (reserved_at, available_at)` for the job claim, `cache (expiration)` for the hourly purge, `purposes (name)`, and a UNIQUE index on `departments (name)`) and **drops the unused tables `job_batches`, `password_reset_tokens`, `sessions` and `prediction_keywords`** (`cache_locks` stays). If two departments share a name, the migration does not fail: it adds a plain index, prints the duplicated names, and adds the UNIQUE index when you run it again after renaming one of them. A manual setup from `database.sql` names the database on the command line: `mysql -u <user> -p <database> < database.sql` (the database must exist: `CREATE DATABASE <database> CHARACTER SET utf8mb4;`). Updating to 2.7.12 or later from an older version also needs the sealing steps in [Chain Seal](CHAIN_SEAL.md) (migrate, check, `php scripts/seal-chains.php`, restart).
