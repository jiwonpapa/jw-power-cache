# Clean lifecycle verification — 2026-09-01

## Scope

- G7 core: `7d628dc4` (`7.0.10` transaction-seam candidate)
- JW PowerCache: `48a39de6` (`0.3.0-alpha.2`)
- Database: MySQL 8.4, isolated empty database, non-privileged application user
- Store: local file driver with `JW_POWER_CACHE_FILE_SINGLE_NODE=true`
- Modules: page `1.1.1`, board `1.1.1`, ecommerce `1.2.1`

This is a disposable clean-instance lifecycle check. It is not production soak or release-upgrade evidence.

## Results

| Gate | Result | Evidence |
|---|---|---|
| Install from release archive | PASS | Plugin registered as `0.3.0-alpha.2`; migrations completed |
| Safe default | PASS | Initial mode was `observe` |
| Doctor and control-plane bootstrap | PASS | Tables/store/barrier/transactional capability and all four route contracts were healthy |
| Activate | PASS | `power-cache:mode active` completed with dirty `0`, pending `0`, emergency dirty `false` |
| Deactivate | PASS | Plugin status became inactive and all four extension middleware registrations disappeared |
| Reactivate | PASS | Runtime epoch changed and all four middleware registrations returned; doctor passed |
| Uninstall with data deletion | PASS | Plugin record, installed directory, state table, and outbox table were removed |
| Reinstall after uninstall | PASS | Fresh site ID/runtime epoch created in observe mode; doctor passed |

## Safety observations

- G7 deliberately refuses extension loading when the application uses a privileged database account. The lifecycle run therefore used a dedicated non-privileged application user.
- Without the explicit single-node acknowledgement, file-store recovery and active HIT remain fail-closed. This is expected behavior, not an activation failure.
- A later official release must repeat this run with the final G7 tag, Redis, an upgrade from the previous supported plugin release, and a release rollback.
