# Local Beta performance evidence — 2026-09-01

## Verdict

The designated expensive board-list route passed the short, uncapped local performance gate at concurrency 1, 4, 16, and 32. Median p95 improved by 28.8–42.4% and median throughput improved by 43.5–69.0%. All measured responses were HTTP 200, the error rate was zero, and the per-route BYPASS and ACTIVE SHA-256 body sets matched.

This is not the full Beta endurance gate. It used PHP's multi-worker development server and 400-request bursts, without a production PHP-FPM/Nginx path, 15–30 minute runs, resource telemetry, mutation traffic, Redis restart, or control-key fault injection during load.

## Environment

- Host: Apple M4 Pro, 12 logical CPUs, macOS 26.6.1
- Runtime: PHP 8.5.3 CLI, Laravel 12.62.0, 32 PHP development-server workers
- Core: Gnuboard 7 transaction-seam commit `7d628dc4e57153a6217372a8a4bf8ea2904c680f`
- Data: 6 pages, 43 ecommerce categories, 8 boards, 268 posts, 955 comments, and 50 users
- Database: MySQL 8.4.11 in a local container
- Cache: Redis 7.4.11 in a local container, isolated DB 7, `noeviction`, zero evicted keys
- Plugin mode alternated BYPASS/ACTIVE before each measurement; the runner restored BYPASS on exit

## Designated board route — three-run median

Each mode processed 400 requests per run. The table reports the median of three alternating-order runs.

| Concurrency | BYPASS p95 | ACTIVE p95 | p95 improvement | BYPASS req/s | ACTIVE req/s | Throughput improvement | Result |
|---:|---:|---:|---:|---:|---:|---:|:---:|
| 1 | 96.665 ms | 63.117 ms | 34.7% | 16.87 | 24.21 | 43.5% | PASS |
| 4 | 118.151 ms | 74.851 ms | 36.6% | 59.19 | 89.82 | 51.7% | PASS |
| 16 | 200.146 ms | 142.422 ms | 28.8% | 114.38 | 169.03 | 47.8% | PASS |
| 32 | 479.976 ms | 276.483 ms | 42.4% | 107.25 | 181.23 | 69.0% | PASS |

Across the 9,600 measured board responses in both modes, every status was 200 and every run had zero application errors. Each ACTIVE run observed one stable response checksum; the corresponding BYPASS checksum set was identical.

## Four-route mixed burst

The four-route mix did not pass the global 20%/20% gate. At concurrency 32, mixed p95 improved by 23.9% and throughput by 15.3%; lower concurrency improvements were smaller. The page and category origins were already low-cost in this local dataset, so their cache overhead diluted board-list gains. This failed result is retained as evidence against enabling broader caching solely to improve latency on cheap routes.

All mixed runs still returned 200 with zero errors and matching per-route BYPASS/ACTIVE checksum sets.

## Reproduction

```bash
PHP_CLI_SERVER_WORKERS=32 php artisan serve --host=127.0.0.1 --port=18087 --no-reload

JWPC_BENCH_ISOLATED=1 \
JWPC_BENCH_CLEAR_RATE_LIMIT=1 \
JWPC_BENCH_ROUTES=board \
JWPC_BENCH_TARGET_RPS=0 \
JWPC_BENCH_MAX_REQUESTS=400 \
JWPC_BENCH_DURATION_SECONDS=60 \
JWPC_BENCH_WARMUP_SECONDS=1 \
JWPC_BENCH_RUNS=3 \
JWPC_BENCH_CONCURRENCIES='1 4 16 32' \
tool/run-benchmark-matrix.sh /path/to/gnuboard7 http://127.0.0.1:18087 /tmp/jwpc-board-evidence
```

The complete Beta gate remains pending until the same matrix passes on a production-equivalent web stack for 15–30 minutes with CPU/RSS, database, and Redis telemetry plus in-load mutation and fault injection.
