# Upgrade and rollback verification — 2026-09-01

## Verdict

The isolated Redis-backed G7 instance passed a forced `0.3.0-alpha.2 → 0.3.0-alpha.1 → 0.3.0-alpha.3` rollback and upgrade rehearsal. Plugin settings, site identity, database state, and application data were preserved. The final candidate passed doctor, deactivate/reactivate epoch rotation, and an Apache/PHP-FPM MISS-to-HIT smoke test with identical response bodies.

This verifies the release process on the G7 transaction-seam candidate. It must be repeated against the final tagged G7 7.0.10 release before JW PowerCache 1.0.

## Environment and artifacts

- Core: Gnuboard 7 transaction-seam commit `7d628dc4e57153a6217372a8a4bf8ea2904c680f`
- Database: MySQL 8.4.11 in an isolated local container
- Cache: Redis 7.4.11, `noeviction`
- Web path: Apache 2.4.67 to PHP-FPM 8.5.3
- Rollback archive: `jw-power_cache-0.3.0-alpha.1.zip`, SHA-256 `f4de0a07628aa4e83a11f14b6764ad231a707c130ab6d62a45e9560e9b33a6ec`
- Upgrade archive: dry-run candidate from commit `365a5bc1`, SHA-256 `28eb24a96deec128d0d5d2b32aa417d93a6ac57f0ad5cf293a6db974f4b40db4`

## Preservation checks

| Check | Before | After final upgrade | Result |
|---|---|---|:---:|
| Plugin version | `0.3.0-alpha.2` | `0.3.0-alpha.3` | PASS |
| Plugin status | active | active | PASS |
| Mode/store driver | bypass/Redis | bypass/Redis | PASS |
| Settings SHA-256 | `7b1066d8ed0772b5a47e3e2d83644b42fce44db5cfe699e6c1301dd0636352f6` | same | PASS |
| Site ID | `c4f92732-5a34-4c26-bbcb-7cd33dee48d2` | same | PASS |
| State rows | 3 | 3 | PASS |
| Pending outbox | 0 | 0 | PASS |
| Doctor warnings/errors | 0/0 | 0/0 | PASS |

The final archive contained only runtime and product-documentation paths. Test, CI, tool, dist, vendor, environment, and Git metadata paths were absent. Updating from the older archive also removed its non-runtime test, tool, and CI directories from the installed plugin tree.

## Lifecycle and cache smoke

- Rollback to alpha.1 completed while the cache mode was BYPASS; doctor remained healthy and the site ID was unchanged.
- Upgrade from alpha.1 to alpha.3 completed with settings and tables intact.
- Deactivation rotated the runtime epoch from `adca2d96-7cdf-4b93-bf00-bbf5cb730491` to `c36d2904-a27b-4b56-b441-08fcf542f8fa`.
- Reactivation rotated it again to `0018c02f-deba-4cf9-b014-87000ae780a6`.
- In ACTIVE mode after a scoped page purge, the first request returned `MISS-STORED` and the second returned `HIT`; both were HTTP 200 and had SHA-256 `49dff322766f812798123e9bfc01684c36d1076bcea0db5fc683ba548a5f5759`.
- The instance was restored to BYPASS with dirty event zero, pending outbox zero, and emergency dirty false.

## Release boundary

No release tag or GitHub release was created. A publishable artifact is intentionally blocked until an exact `v0.3.0-alpha.3` tag points to a clean release commit; CI uses the explicit dry-run verification mode.
