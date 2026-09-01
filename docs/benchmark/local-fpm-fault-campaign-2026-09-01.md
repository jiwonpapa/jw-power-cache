# Local PHP-FPM fault campaign — 2026-09-01

## Verdict

The 15-minute concurrency-16 campaign passed. All 7,203 responses were HTTP 200, with zero errors, zero stale responses, and zero personalized-field leaks while a committed page mutation, scoped purge, selective generation-key loss, and Redis restart overlapped live traffic. Both destructive cache faults rotated the runtime epoch and recovered automatically.

This closes the concurrency-16 fault-injection portion of the Beta gate. It does not close the full Beta endurance matrix, which still requires 15–30 minute mixed-route measurements at concurrency 1, 4, 16, and 32.

## Environment

- Host: Apple M4 Pro, macOS 26.6.1
- Web path: Apache 2.4.67 to PHP-FPM 8.5.3 over FastCGI
- PHP-FPM: 32 static workers
- Core: Gnuboard 7 transaction-seam commit `7d628dc4e57153a6217372a8a4bf8ea2904c680f`
- Database: MySQL 8.4.11 in an isolated local container
- Cache: Redis 7.4.11 in an isolated restartable container, `noeviction`
- Request target: a temporary published page created and mutated through the official PageService

## Results

| Duration | Concurrency | Target RPS | Requests | Actual RPS | p50 | p95 | p99 | HTTP/error/stale/personalized | Result |
|---:|---:|---:|---:|---:|---:|---:|---:|---|:---:|
| 30 s | 4 | 4 | 123 | 4.05 | 60.057 ms | 164.901 ms | 494.931 ms | 123/0/0/0 | PASS |
| 120 s | 16 | 8 | 963 | 7.98 | 89.364 ms | 231.190 ms | 490.709 ms | 963/0/0/0 | PASS |
| 902 s | 16 | 8 | 7,203 | 7.99 | 85.669 ms | 122.428 ms | 200.236 ms | 7,203/0/0/0 | PASS |

The 15-minute run observed 5,714 direct hits, 30 hits after fill-lock wait, 3 stored misses, 1,443 fail-closed emergency-dirty bypasses, and 13 barrier-error bypasses. Bypasses returned origin responses and did not expose stale or personalized data.

## Fault gates

| Injected action | Observed recovery | Result |
|---|---|:---:|
| Committed page mutation | Outbox event advanced from 51 to 52; only the old or new valid token was accepted during the overlap window | PASS |
| Scoped page purge | Outbox event advanced from 52 to 53 | PASS |
| Delete `page:all` generation key | Exactly one key deleted; runtime epoch rotated | PASS |
| Restart Redis | Container returned to running; runtime epoch rotated again | PASS |

After the run, the plugin was restored to BYPASS, doctor reported no errors or warnings, pending outbox was zero, emergency dirty was false, the temporary page count was zero, and Redis was running.

## Resource telemetry

Sixty samples were taken at 15-second intervals during the 15-minute run. The 32-worker PHP-FPM pool stayed at 32 workers. Aggregate process CPU averaged 61.80% and peaked at 213.40%; aggregate RSS averaged 1,370,153 KiB and peaked at 1,400,640 KiB. These figures are host-local capacity observations, not production sizing guidance.

## Reproduction

```bash
JWPC_BENCH_ISOLATED=1 tool/run-fault-campaign.php \
  --g7-root=/path/to/gnuboard7 \
  --base-url=http://127.0.0.1:18088 \
  --redis-container=jwpc-test-redis \
  --duration=900 \
  --concurrency=16 \
  --rps=8 \
  --output=/tmp/jwpc-fault-900s-c16.json
```

The output path is fail-closed and will not be overwritten. The campaign requires `JWPC_BENCH_ISOLATED=1`, an exact non-auto-remove Redis container name, and an isolated G7 instance because it creates data, changes cache mode, deletes a control key, and restarts Redis.
