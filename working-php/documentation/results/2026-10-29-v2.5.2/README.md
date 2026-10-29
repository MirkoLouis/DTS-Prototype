# Final evidence runs, 2026-10-29 (v2.5.2, code at commit 30a89d8)

This is the second evidence set, run after the SMTP removal and after the simultaneous-completion test was made repeatable. It does not replace `../2026-10-29/`; that folder holds the earlier set. **Use this set for Chapters 4–5.** It is the newest and the only one in which both concurrency tests ran five times.

Each folder is a copy of one `storage/test-results/<run_id>/` run folder:
- `run.json` records the run id, the start and finish times (UTC+08:00), the commit, PHP, the CPU and the RAM.
- `ENVIRONMENT.md` describes the hardware, the software and the running system (`composer run dev`).
- All runs were made on the same laptop while the full system (nginx, PHP-FPM, MySQL, worker) was running.

| Folder | Run id | Contents | Result |
|---|---|---|---|
| `full-suite/` | 20261029-173659-01a3 | Full default suite (unit, workflow, HTTP, security). `test-outcomes.csv` has one row per test. | **171 / 171 passed** |
| `security-run-1/` … `security-run-5/` | 20261029-173912-3da5, -173950-f9ad, -174030-2390, -174108-13cf, -174147-39bc | Security suite × 5: `tamper-matrix.csv` (18 tactics + 1 control), `http-attacks.csv` (76 checks), `signatures.csv` | **40 / 40 passed in each run** |
| `concurrency-5-runs/` | 20261029-174241-fdcd | `concurrency.csv`: 100 simultaneous kiosk submissions × 5 runs; 5 simultaneous completions of one step × 5 runs | **4 / 4 passed**: 100/100 distinct, correctly matched codes in every run; exactly 1 of 5 completions succeeded in every run |
| `concurrency-attempt-failed-parallelism-check/` | 20261029-173844-a7fa | The first concurrency attempt, kept for transparency | 3 of 4 tests passed. The failed one is the timing pre-check that confirms the test server runs requests in parallel (batch 0.098 s vs. limit 0.097 s, under background load). It is not a concurrency-control failure, and the suite was re-run (folder above). |
| `benchmark-100k/` | 20261029-174323-19c6 | `benchmark.csv`: 1,000 iterations per operation on **100,000 seeded documents (634,555 log entries)** | **passed**; every p95 < 1,000 ms |
| `route-replay/` | 20261029-174504-1151 | Route-suggestion replay of the legacy export: overall, by year, by purpose, baselines, purpose profile, config | heuristic **57.91%** exact / **66.85%** first department; baselines 23.49% (most frequent so far), 23.52% (hindsight), 0% (none) |

## Benchmark p95 (ms), 100,000 documents

| Operation | p95 (ms) |
|---|---|
| state hash | 0.0022 |
| hash and append a log entry | 1.82 |
| read a document's log | 0.18 |
| Security PIN unlock (Argon2id + AES) | 34.76 |
| Ed25519 sign | 0.020 |
| Ed25519 verify | 0.056 |
| verify one document (chain + signatures) | 0.76 |
| pre-action integrity check | 0.10 |
| `GET /admin-dashboard`, cold | 1.49 |
| `GET /admin-dashboard`, warm | 0.90 |
| `GET /intake` | 24.34 |
| `GET /tasks` | 2.02 |
| `GET /track` | 1.34 |
| `GET /documents/{code}` | 1.76 |

HTTP timings come from the test suite's own PHP server (`php -S`, 16 workers), not from nginx/PHP-FPM.

The raw legacy export used by the replay (`from_dts/`) is private DepEd data. It is kept only on the researchers' laptop and is not in this repository. The replay output contains only aggregate counts and the five generic purpose labels.
