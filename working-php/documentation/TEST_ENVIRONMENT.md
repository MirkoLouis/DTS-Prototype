# Test Environment

The environment in which the automated test suite, benchmark and route-suggestion replay were run (2026-10-29). Cite this for the hardware and software statements in Sections 3.3 and 4.3.

## Hardware (researcher-provided laptop)

| Component | Specification |
|---|---|
| Machine | MACHENIKE NP55HH_J_K-HMx (laptop) |
| Processor | 11th Gen Intel Core i5-11260H @ 2.60 GHz (boost up to 4.4 GHz), 6 cores / 12 threads; AES-NI, SHA, AVX2 and AVX-512 supported |
| Memory | 15,762 MB (about 16 GB) RAM |
| Storage | 2 SSDs: 1 TB and 512 GB (NVMe) |
| Graphics | Intel UHD Graphics (Tiger Lake GT1) and NVIDIA GeForce RTX 3050 Ti Mobile (not used by the system) |
| Network | Realtek RTL8111 Gigabit Ethernet; Intel CNVi Wi-Fi |
| Camera | BisonCam NB Pro (built-in; used for QR scanning) |

## Software

| Layer | Version |
|---|---|
| Host operating system | NixOS 26.11 (Zokor), Linux kernel 7.2, Hyprland desktop |
| Application environment | Ubuntu 26.04.1 LTS container (Distrobox) on the NixOS host; the home directory is shared with the host |
| PHP | 8.5.4 (CLI and PHP-FPM), with OPcache enabled; the code is written to PHP 8.3 syntax |
| Web server | nginx 1.28.3, HTTPS on port 8000 with a self-signed certificate (needed for browser camera access on the LAN) |
| Database | MySQL 8.4.10, data directory `~/mysql-data`; `innodb_buffer_pool_size` = 128 MB (default), `max_connections` = 151, `innodb_flush_log_at_trx_commit` = 1 |
| Test framework | PHPUnit 12.5 |

## System running during the tests (`composer run dev`)

The full system was running while the tests ran. `composer run dev` (`scripts/dev.php`) does the following:
- Clears the application cache.
- Restarts **PHP-FPM 8.5**, which flushes OPcache. Pool: `pm = dynamic`, `max_children = 10`, `start_servers = 4`, `min_spare_servers = 2`, `max_spare_servers = 6`.
- Creates the self-signed certificate for the detected LAN IP if missing, installs the nginx site and restarts **nginx** on `https://<LAN IP>:8000`.
- Starts the background **queue worker** (`console.php`), logging to `storage/logs/worker.log`.
- Tails the nginx access log.

Application settings in `.env` during the tests:
- `APP_DEBUG=true`
- `PIN_CACHE_DURATION=72000` (20 hours)

The paper should state the value used during the evaluation (M13). `.env.example` now defaults to 900 seconds (15 minutes).

## Data

- **Operational database `deped_dts`:** 100,000 seeded documents with 779,706 signed log entries. It was not touched by the tests.
- **Test database `deped_dts_test`:** created fresh before each test run and dropped afterwards (`TestLifecycle`). The automated tests never read or write `deped_dts`.
- **Benchmark:** seeds **100,000 documents** (633,214 signed log entries) into `deped_dts_test` with `scripts/seeders/fast-seed.php` before measuring:
  ```
  BENCH_DOCS=100000 BENCH_ITERATIONS=1000 composer bench
  ```
  Seeding plus measurement took 104 s. Use `BENCH_DOCS=1000000` for the 1,000,000-record run in the paper (about 14 minutes of seeding).
- **Route replay:** the legacy export `from_dts/dts_docroutes (1).csv` (163,038 routing rows; 38,020 qualifying documents).

## Notes for interpreting timings

- The tests ran on the same laptop as the running system. nginx, PHP-FPM, MySQL and the worker were all active, so timings include that background load.
- HTTP timings in the benchmark and the concurrency tests come from the test suite's own PHP built-in server (`php -S`, 16 workers) on a separate port, not from nginx/PHP-FPM. The nginx/FPM figures come from the user-run `composer manual:live-concurrency` script.
- The CPU supports AES-NI and the SHA extensions, which speed up hashing and encryption. State the processor model when reporting the cryptographic timings.

## Results of the 2026-10-29 run

| Run folder (`storage/test-results/`) | Contents | Result |
|---|---|---|
| `20261029-154416-9a1e` | Default suite: unit, workflow, HTTP, security (tamper matrix, HTTP attacks, signature verification) | 170 / 170 passed |
| `20261029-154601-38ec` | Concurrency suite: 100 simultaneous kiosk submissions; 5 simultaneous completions of one step | 4 / 4 passed |
| `20261029-154630-b4ae` | Performance benchmark at 100,000 documents (633,214 log entries), 1,000 iterations per operation | passed; every p95 < 1,000 ms |
| `20261029-074612-24aa` | Route-suggestion replay with baselines | heuristic 57.91% exact / 66.85% first department; baselines 23.49% / 23.52% / 0% |

Benchmark p95 times at 100,000 documents (ms):

| Operation | p95 (ms) |
|---|---|
| state hash | 0.0023 |
| hash and append a log entry | 1.86 |
| read a document's log | 0.16 |
| PIN unlock (Argon2id + AES) | 33.0 |
| Ed25519 sign | 0.018 |
| Ed25519 verify | 0.052 |
| verify one document (chain + signatures) | 0.72 |
| pre-action integrity check (row `active_guard_check` in this run's CSV; renamed `pre_action_integrity_check` from 2.5.1) | 0.10 |
| `/admin-dashboard`, cold | 1.47 |
| `/admin-dashboard`, warm | 0.91 |
| `/intake` | 25.2 |
| `/tasks` | 2.08 |
| `/track` | 1.39 |
| `/documents/{code}` | 1.85 |
