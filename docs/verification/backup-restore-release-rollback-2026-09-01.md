# Backup restore and release rollback verification — 2026-09-01

## Verdict

The isolated Redis-backed G7 instance passed a destructive logical backup/restore drill and a release rollback cycle from the current `0.3.0-alpha.3` candidate to `0.3.0-alpha.2` and back. Settings and site identity were preserved, restored outbox data matched the backup, the runtime epoch rotated, and a response retained in Redis under the old epoch was not reusable.

This is local release-candidate evidence on the pinned G7 transaction-seam commit. No production system, release tag, or GitHub release was changed.

## Environment and artifacts

- Core: Gnuboard 7 commit `7d628dc4e57153a6217372a8a4bf8ea2904c680f`
- Database/cache: MySQL 8.4.11 and Redis 7.4.11 with `noeviction`
- Database: isolated `g7_powercache_install`
- Candidate source commit: `67fd1b68`
- Rollback archive: `0.3.0-alpha.2`, SHA-256 `290080fe88394ad10398f0d29c9df44d4ed45f78e45aa80707db20d3fa4bd02b`
- Upgrade archive: `0.3.0-alpha.3`, SHA-256 `3fc601a31ac5e69cfad87f5ea4a3d2c19007980235cb6c4dc75db4e9c05193bb`

## Backup and restore drill

The drill required `APP_ENV=local`, an exact expected database name, explicit destructive authorization, `bypass` mode, and maintenance mode. It backed up the raw settings file plus all rows from the two JW PowerCache tables, wrote a private temporary backup, inserted a corrupt site identity/runtime epoch/pending outbox event, changed a setting, restored the backup, and ran `power-cache:restore-finalize --yes`.

| Check | Result |
|---|:---:|
| Backup SHA-256 `9a973754e6b745bf6c994c9c228d2e29224741cca79544d71e6dce3ee9909dfa` read back | PASS |
| Settings SHA-256 `7b1066d8ed0772b5a47e3e2d83644b42fce44db5cfe699e6c1301dd0636352f6` restored exactly | PASS |
| Site ID `c4f92732-5a34-4c26-bbcb-7cd33dee48d2` preserved | PASS |
| Three state rows and 66 outbox rows restored | PASS |
| Runtime epoch rotated from `5b65cec0-7bd7-416c-a896-68f71ae48a57` to `d2054deb-6d28-4b1c-acfa-620a082db09b` | PASS |
| Dirty event and pending outbox returned to zero | PASS |
| Emergency barrier clean and runtime snapshot current | PASS |
| Redis response carrying the pre-restore epoch rejected by epoch mismatch | PASS |
| Post-restore doctor warnings/errors | `0 / 0` |

The command also failed closed as designed: without `--yes` it exited 2 without mutation, and while the site was online it exited 1 with a maintenance-mode error.

## Release rollback cycle

The active installed plugin was forced from the current alpha.3 candidate to the exact alpha.2 archive and then upgraded back to the current alpha.3 archive. The active installation path returned to `0.3.0-alpha.3`; the restored settings checksum and site ID remained unchanged, mode/store remained `bypass`/Redis, pending outbox remained zero, and doctor/status reported no warning or error. The new `power-cache:restore-finalize` command was absent on alpha.2 and present again after the alpha.3 upgrade, proving that the active code path actually changed.

G7 keeps the original `_bundled` source separately from the active `plugins/jw-power_cache` installation. Version assertions used the active path and plugin database record rather than assuming the bundled source was live.

## Operational boundary

The procedure deliberately does not restore Redis. Cache responses are disposable; restoring an old Redis snapshot alongside an old DB epoch would create avoidable stale-data risk. Operators must restore the database, settings, and plugin artifact under maintenance mode, run `restore-finalize`, pass doctor, and only then reopen traffic.
