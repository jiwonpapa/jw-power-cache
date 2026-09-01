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

The 2026-08-23 online A/B is a direction and smoke result, not the final Beta endurance gate. Its board route nevertheless meets the primary latency and throughput target with zero errors.
