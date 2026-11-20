# Test Environment (2026-11-19, v2.7.15)

The environment in which the automated test suite, benchmark and route-suggestion replay were run. It is the same laptop as the 2026-10-29 sets. Cite this for the hardware and software statements in Sections 3.3 and 4.3.

## Hardware (researcher-provided laptop)

| Component | Specification |
|---|---|
| Machine | MACHENIKE NP55HH_J_K-HMx (laptop) |
| Processor | 11th Gen Intel Core i5-11260H @ 2.60 GHz (boost up to 4.4 GHz), 6 cores / 12 threads; AES-NI, SHA, AVX2 and AVX-512 supported |
| Memory | 15.4 GB RAM as reported by the runs (about 16 GB installed) |
| Storage | 2 SSDs: 1 TB and 512 GB (NVMe) |

## Software

| Layer | Version |
|---|---|
| Host operating system | NixOS (Hyprland desktop) |
| Application environment | Ubuntu 26.04.1 LTS container (Distrobox) on the NixOS host |
| PHP | 8.5.4 (CLI and PHP-FPM), OPcache enabled |
| Web server | nginx 1.28.3, HTTPS on port 8000 with a self-signed certificate |
| Database | MySQL 8.4.11; `innodb_buffer_pool_size` = 128 MB (default), `max_connections` = 151, `innodb_flush_log_at_trx_commit` = 1 |
| Test framework | PHPUnit 12.5.42 |

## System running during the tests (`composer run dev`)

The full system was running while the tests ran, as in the 2026-10-29 sets: PHP-FPM 8.5 (`pm = dynamic`, `max_children = 10`, `start_servers = 4`), nginx on `https://<LAN IP>:8000` with the project's `site.conf` (security headers enforced), and the background queue worker (`console.php`).

Application settings in `.env` during the tests: `APP_DEBUG=true`, `PIN_CACHE_DURATION=72000` (20 hours), `SESSION_LIFETIME=43200`, `BACKUP_KEEP_DAYS=14`, `RATE_LIMIT_ENABLED=false`. The last applies only to the running development server; the test suite starts its own servers with the limiter on (and off only for the runs that send more requests than the limits allow), so it did not affect the results.

## Data

- **Operational database `deped_dts`:** 100,002 documents with 643,759 signed log entries, sealed (chain seal v2). It was not touched by the tests.
- **Test database `deped_dts_test`:** rebuilt before each test run. The automated tests never read or write `deped_dts`.
- **Benchmark:** seeds **100,000 documents** (635,073 signed log entries) into `deped_dts_test` with `scripts/seeders/fast-seed.php` before measuring. Seeding plus measurement took 352 s.
- **Route replay:** the legacy export (163,038 routing rows; 38,020 qualifying documents), kept off the repository.

## Notes for interpreting timings

- nginx, PHP-FPM, MySQL and the worker were active, so timings include that background load.
- HTTP timings come from the test suite's own PHP built-in server (`php -S`, 16 workers) on a separate port, not from nginx/PHP-FPM.
- State the processor model when reporting the cryptographic timings; the CPU supports AES-NI and the SHA extensions.
