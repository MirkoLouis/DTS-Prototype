# Evidence runs, 2026-11-19 (v2.7.15, code at commit 0f7e6c0)

This is the third evidence set. It replaces neither `../2026-10-29/` nor `../2026-10-29-v2.5.2/`; those record the earlier builds. This set was run after the v2.7 changes: user deactivation, credential rules, guest rate limiting, the enforced Content Security Policy, the chain seal (including the release date) and the operations work.

Each folder is a copy of one `storage/test-results/<run_id>/` run folder:
- `run.json` records the run id, start and finish times (UTC+08:00), the command, the commit (`v2.0.0-114-g0f7e6c0`, clean), PHP, the CPU and the RAM.
- `summary.csv` gives the test counts per suite; `test-outcomes.csv` has one row per test.
- All runs were made on the same laptop as v2.5.2, while the full system (nginx, PHP-FPM, MySQL, worker) was running through `composer run dev`. See `ENVIRONMENT.md`.

## What each run is for

| Folder | Command | What it runs | Goal |
|---|---|---|---|
| `full-suite/` | `vendor/bin/phpunit` | Every default suite: unit, workflow, HTTP and security tests | Show the whole system behaves as specified: workflow rules, access control, credentials, rate limits, error pages, security headers, backups, seals and tamper detection, in one run |
| `security-run-1/` … `security-run-5/` | `composer test:security` (five times) | The security suite: the tamper-injection matrix, HTTP attacks, signature verification and PIN-token tests | Show the security results are repeatable, not a one-off: the same tactics are detected (or, for the documented limits, not detected) in every run |
| `concurrency-5-runs/` | `CONCURRENCY_RUNS=5 composer test:concurrency` | 100 simultaneous guest submissions and 5 simultaneous completions of one step, each repeated 5 times; 30 simultaneous submissions against the rate limit, and two checks that the workers really run in parallel, once each | Show that simultaneous use never produces duplicate tracking codes, lost documents, a double-completed step, or more submissions than the rate limit allows |
| `benchmark-100k/` | `BENCH_DOCS=100000 BENCH_ITERATIONS=1000 composer bench` | Seeds 100,000 documents into the test database, then times each operation 1,000 times (100 for the PIN unlock) | Measure how fast the cryptographic and page operations are at a realistic data size, and confirm every one stays well under one second |
| `route-replay/` | `php scripts/route-replay.php "<legacy export>"` | Replays the legacy DepEd routing export through the route-suggestion heuristic | Measure how often the suggested route matches the route the office actually used. Routing did not change since v2.5.2, so the result must be identical |

## Results

| Run | Run id | Result |
|---|---|---|
| Full suite | 20261119-105830-275d | **744 / 744 passed** (unit 176, workflow 235, HTTP 273, security 60) |
| Security × 5 | 20261119-111001-3aa2, -111117-bed9, -111228-d52d, -111339-9cc2, -111447-cc0e | **60 / 60 passed in each run.** Every run: 28 tamper-matrix rows (27 tactics and 1 untouched control), each detected or not detected exactly as expected (0 deviations); 76 HTTP attack cases (CSRF, stored XSS, SQL injection, access control and others), each handled exactly as expected; 14 signature checks, all as expected |
| Concurrency × 5 | 20261119-111627-8cc7 | **5 / 5 tests passed.** Guest submissions: 100 / 100 succeeded in every run, 100 distinct tracking codes, 0 mismatches, 0 log faults. Step completion: exactly 1 of 5 succeeded in every run, 4 conflicts, 0 unaccounted outcomes. Rate limit: of 30 simultaneous submissions from one address, exactly 10 were accepted |
| Benchmark | 20261119-111932-daea | **Passed**; every p95 under 1,000 ms (100,000 documents, 635,073 log entries) |
| Route replay | 20261119-113135-2c36 | Heuristic **57.91 %** exact route, **66.85 %** first department (38,015 scored of 38,020). Identical to v2.5.2 |

## Tamper matrix (every security run)

| Group | Tactics | Outcome |
|---|---|---|
| Content edits (title, submitter e-mail, route, corrupt script) | 4 | Detected by the pre-action check and the integrity check |
| Workflow-state edits (status, step, holder, decline reason, **release date**) | 5 | Detected by both, through the chain seal (status, step and holder were undetected before v2.7.12; the release date before v2.7.15) |
| Log edits covered by a hash (newest state hash, snapshot field, middle or tail deletion, appended or forged entries, missing seal, rewritten chain) | 11 | Detected by both |
| Log fields outside the pre-action check (remarks, action, timestamp, user id) | 4 | Detected by the integrity check only, as designed |
| Swapped log payloads | 1 | Detected by the integrity check; the pre-action check is observe-only for this row |
| Privileged insider holding the server key | 2 | Not detected by the seal (documented limit); deleting a tail entry is still caught by the off-machine chain-head checkpoint |
| Untouched control | 1 | No false alarm |

## Benchmark p95 (ms), 100,000 documents

| Operation | v2.5.2 | v2.7.15 |
|---|---|---|
| state hash | 0.0022 | 0.0033 |
| hash and append a log entry (now also writes both seals) | 1.82 | 5.47 |
| chain seal refresh (new) | n/a | 1.18 |
| read a document's log | 0.18 | 0.55 |
| Security PIN unlock (Argon2id + AES) | 34.76 | 50.61 |
| Ed25519 sign | 0.020 | 0.034 |
| Ed25519 verify | 0.056 | 0.089 |
| verify one document (chain + signatures) | 0.76 | 1.29 |
| pre-action integrity check (now also checks both seals) | 0.10 | 0.64 |
| `GET /admin-dashboard`, cold | 1.49 | 3.45 |
| `GET /admin-dashboard`, warm | 0.90 | 2.30 |
| `GET /intake` | 24.34 | 46.44 |
| `GET /tasks` | 2.02 | 4.50 |
| `GET /track` | 1.34 | 2.91 |
| `GET /documents/{code}` | 1.76 | 4.05 |

HTTP timings come from the test suite's own PHP server (`php -S`, 16 workers), not from nginx/PHP-FPM.

**Reading the comparison.** Most operations are slower than in v2.5.2, including ones this version did not change (state hash, Ed25519, the PIN unlock), so part of the difference is the laptop's load and thermal state on the day rather than the code. The changes that do add work are the seals: appending an entry now computes and stores two HMACs, and the pre-action check verifies them. Every operation remains far under the 1,000 ms limit; the slowest, the intake page, is 46 ms.

## Not in this set

- Live NGINX/PHP-FPM timings and the tracking-slip PDF in a browser's built-in viewer under the enforced policy (see CHANGELOG 2.7.11).
- A 1,000,000-document benchmark (`BENCH_DOCS=1000000`).
- Screen-reader testing (the accessibility checks are markup and keyboard checks in headless Chromium).

The raw legacy export used by the replay (`from_dts/`) is private DepEd data. It is kept only on the researchers' laptop and is not in this repository. The replay output contains only aggregate counts; the input path appears in `run.json` and `route-replay-config.csv` as it did in the v2.5.2 set.
