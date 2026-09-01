# Release checklist

The current alpha verification snapshot is recorded in [clean lifecycle evidence](verification/clean-lifecycle-2026-09-01.md). Check a box only for the exact release commit and artifact being published; prior alpha evidence is supporting evidence, not a substitute.

## Code gate

- [ ] Manifest, changelog, and tag versions match.
- [ ] Composer metadata, PHP syntax, Pint, and all tests pass.
- [ ] PHP/G7/Redis CI matrix passes.
- [ ] MySQL and MariaDB transaction matrix passes.
- [ ] Settings defaults, backend schema, public exposure policy, and admin form contract match.

## Runtime gate

- [ ] Clean install starts in `observe` mode.
- [ ] `doctor`, MISS→HIT, scoped purge, rollback, Redis restart, and selective control-key loss pass.
- [ ] Upgrade preserves DB state and invalidates incompatible cache entries through format/policy versioning.
- [ ] Deactivate and rollback rotate the runtime epoch and return traffic safely to origin.

## Evidence and publication

- [ ] Benchmark protocol and raw before/after evidence are attached.
- [ ] Known limitations and compatibility changes are in release notes.
- [ ] Release archive contains no tests, CI configuration, credentials, or local artifacts.
- [ ] SHA-256 checksum and GitHub build-provenance attestation are published.
- [ ] Tag commit is reachable from `main`.
