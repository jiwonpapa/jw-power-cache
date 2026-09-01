# Performance acceptance methodology

## Primary target

For a designated expensive public route, warm active mode must achieve both:

- p95 latency at least 20% lower than bypass mode;
- throughput at least 20% higher than bypass mode;
- error rate no worse than 0.1%, with zero incorrect or personalized responses.

Routes whose bypass p95 is already below 150ms are tracked as low-ROI routes and do not justify broader caching solely on latency.

## Protocol

1. Use the same host, dataset, PHP-FPM pool, database, Redis, and network path for bypass and active runs.
2. Warm each route before measurement.
3. Run a short correctness smoke, then 15–30 minute mixed traffic at concurrency 1, 4, 16, and 32.
4. Record p50/p95/p99, requests/sec, errors, PHP CPU/RSS, DB queries, Redis ops/memory/evictions, and origin response checksums.
5. During load, execute supported mutations, scoped purge, Redis restart, and selective control-key deletion.
6. Repeat at least three times and use the median run. Keep raw output with the release evidence.

The supported page and board routes preserve their core `throttle:600,1` middleware on both BYPASS and HIT. Both routes share the same guest IP limiter key, so a single-client benchmark must stay below their combined ceiling instead of treating HTTP 429 as application errors or cache speedup. The bundled matrix defaults to 16 total requests/second, evenly distributed across four routes (about 480 requests/minute across the two throttled routes).

```bash
JWPC_BENCH_DURATION_SECONDS=900 \
JWPC_BENCH_WARMUP_SECONDS=10 \
JWPC_BENCH_RUNS=3 \
JWPC_BENCH_TARGET_RPS=16 \
tool/run-benchmark-matrix.sh /path/to/gnuboard7 https://target.example.com
```

The runner always returns the plugin to `bypass` on completion or interruption. Run it only against an explicitly approved target because it changes the plugin mode while the matrix is active. It refuses to overwrite an evidence directory containing a prior run; use a new directory for every matrix.

For an approved remote production-equivalent target, use the SSH orchestrator. It preserves and restores the original mode, alternates mode order, and emits mixed plus per-route comparisons. Fixed-rate remote runs are endurance and correctness evidence, not throughput evidence.

```bash
JWPC_BENCH_REMOTE_APP_USER=remote-app-user \
JWPC_BENCH_CONCURRENCY=5 \
JWPC_BENCH_TARGET_RPS=8 \
tool/run-remote-benchmark-matrix.sh \
  ssh-target /absolute/g7/root https://target.example.com /new/evidence/path
```

The first published remote result is the [g7devops.com 5VU report](g7devops-live-5vu-2026-09-01.md).

The fixed-rate matrix cannot prove throughput improvement because both modes are intentionally capped. Run a separate request-bounded uncapped burst only on an isolated benchmark instance. Clearing the application cache resets the shared rate limiter before each mode; the explicit isolation acknowledgement prevents accidental use on a live site.

```bash
JWPC_BENCH_ISOLATED=1 \
JWPC_BENCH_CLEAR_RATE_LIMIT=1 \
JWPC_BENCH_TARGET_RPS=0 \
JWPC_BENCH_MAX_REQUESTS=400 \
JWPC_BENCH_DURATION_SECONDS=60 \
JWPC_BENCH_RUNS=3 \
tool/run-benchmark-matrix.sh /path/to/gnuboard7 http://127.0.0.1:18087
```

Measure the designated expensive route separately from the four-route mix so cheap origin routes do not dilute its throughput result:

```bash
JWPC_BENCH_ISOLATED=1 \
JWPC_BENCH_CLEAR_RATE_LIMIT=1 \
JWPC_BENCH_ROUTES=board \
JWPC_BENCH_TARGET_RPS=0 \
JWPC_BENCH_MAX_REQUESTS=400 \
JWPC_BENCH_RUNS=3 \
tool/run-benchmark-matrix.sh /path/to/gnuboard7 http://127.0.0.1:18087
```

Every comparison fails closed when the BYPASS and ACTIVE per-route SHA-256 response sets differ. Raw result files retain checksum counts so the correctness decision is independently inspectable.

## Concurrent fault campaign

Run the mutation and recovery gate against an isolated G7 instance through its production-equivalent HTTP stack. The campaign creates a temporary published page through the official `PageService`, overlaps requests with a committed update and scoped purge, deletes the `page:all` generation key, restarts Redis, and then removes its fixture. It fails on any non-200 response, stale token, public response containing personalized fields, missing outbox advance, missing epoch rotation, failed Redis recovery, or incomplete cleanup.

The Redis container must have `AutoRemove=false`; the tool refuses `docker run --rm` containers so restart cannot accidentally turn into permanent container removal. The exact container name is validated and passed to Docker without a shell.

```bash
JWPC_BENCH_ISOLATED=1 \
tool/run-fault-campaign.php \
  --g7-root=/path/to/gnuboard7 \
  --base-url=http://127.0.0.1:18088 \
  --redis-container=jwpc-test-redis \
  --duration=900 \
  --concurrency=16 \
  --rps=8 \
  --output=/new/evidence/path/fault-campaign.json
```

The default 8 requests/second stays below the page route's 600 requests/minute guest limit while preserving concurrent batches. Repeat at concurrency 1, 4, 16, and 32 on the release candidate; a single 15-minute run is evidence for that concurrency only.

The 2026-08-23 online A/B is a direction and smoke result, not the final Beta endurance gate. Its board route nevertheless meets the primary latency and throughput target with zero errors.
