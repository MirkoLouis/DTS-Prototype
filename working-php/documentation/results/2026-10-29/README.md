# Final evidence runs, 2026-10-29 (code at commit 1664c56, v2.5.1)

These are the test runs used for the results in Chapters 4–5. Each folder is a copy of one `storage/test-results/<run_id>/` run folder. `run.json` in each folder records the run id, the start and finish times (UTC+08:00), the commit, the PHP version, the CPU and the RAM. `ENVIRONMENT.md` describes the hardware, the software and the running system (`composer run dev`).

| Folder | Original run id | Contents | Result |
|---|---|---|---|
| `full-suite/` | 20261029-154416-9a1e | Full default suite: unit, workflow, HTTP, security. `test-outcomes.csv` has one row per test. | 170 / 170 passed |
| `security-run-1/` … `security-run-5/` | 20261029-170802-f6c9, -170841-a673, -170922-ccd3, -171001-fe27, -171040-1094 | Security suite repeated 5 times: `tamper-matrix.csv` (18 tactics + 1 control), `http-attacks.csv` (76 checks), `signatures.csv` | 40 / 40 passed in each run; every non-null tamper expectation met; 0 failed attack checks |
| `concurrency-5-runs/` | 20261029-170743-d6cb | `concurrency.csv`: 100 simultaneous kiosk submissions × 5 runs (cold page cache), plus 5 simultaneous completions of one step (1 run; `CONCURRENCY_RUNS` repeats only the kiosk test) | 100 distinct, correctly matched codes in every run; exactly 1 of 5 completions succeeded |
| `benchmark-100k/` | 20261029-154630-b4ae | `benchmark.csv`: 1,000 iterations per operation on 100,000 seeded documents (633,214 log entries). The row `active_guard_check` is the pre-action integrity check (renamed in 2.5.1). | every p95 < 1,000 ms |
| `route-replay/` | 20261029-074612-24aa (folder named in UTC, before the 2.5.1 fix) | Route-suggestion replay of the legacy export: overall, by year, by purpose, baselines, purpose profile, config | heuristic 57.91% exact / 66.85% first department; baselines 23.49%, 23.52%, 0% |

To reproduce, see the README Testing section. Live runs write to `storage/test-results/`, which is not committed.
