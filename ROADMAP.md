# Roadmap and release gates

## Current: 0.3.0-alpha.2 Technical Preview

- conservative public guest API allowlist;
- durable invalidation outbox and generation cache;
- missing control-key recovery with runtime-epoch rotation;
- rollback-safe emergency barrier;
- G7 7.0.10 same-transaction mutation seam integration and fail-closed capability gate;
- Redis, SQLite, MySQL, and MariaDB regression matrix;
- reproducible release archives with checksums and provenance attestation.

## Beta gate

- all automated quality and database matrix jobs green;
- mixed-route 15–30 minute load test at concurrency 1/4/16/32;
- concurrent write, purge, Redis restart, and selective-key-loss tests with zero stale/personalized responses;
- p95 latency and throughput improve by at least 20% on designated expensive public routes, with error rate no worse than 0.1%;
- install, upgrade, deactivate, uninstall, and rollback runbook verified on a clean G7 instance.

## 1.0 gate

- G7 7.0.10 same-transaction mutation seam is officially released and the plugin CI no longer depends on a feature branch;
- site-wide settings and extension lifecycle changes have a transactional seam or an automatic maintenance barrier;
- at least 30 days of production soak across 5 or more independent sites with no cache-caused data exposure or stale-content incident;
- restore drill, release rollback, security disclosure, support boundary, and compatibility policy verified;
- published signed release notes and checksums for every supported artifact.

Until the same-transaction seam and soak gates pass, the project must not be marketed as an absolute-consistency cache or a whole-site cache.
