# Architecture and failure model

## Request path

Eligible requests pass an explicit route and presentation contract. Public board requests use a public key; authenticated board requests use a user-ID-isolated key and repeat the original read-permission check before HIT delivery. Active mode reads a clean control barrier, runtime snapshot, generation vector, and cached response. Any missing, malformed, dirty, or mismatched state becomes a MISS/BYPASS; it is never interpreted as generation zero.

GET/HEAD requests with raw, parsed, or uploaded body input bypass the cache because controllers may read body fields through `all()`/`input()` while keys only include allowed query parameters. Date variants use G7's effective user timezone, not the PHP process timezone. Policy `response-api-v4` separates these responses from entries stored before the boundary fixes.

After reading a cached entry, all three HIT paths recheck a clean barrier, the original site/runtime epoch, and current generations, then require the barrier token to remain unchanged across that check. Origin fills use the same final check. This detects mutations and resets that overlap the cache read without adding database queries to normal HITs; it does not remove the stock G7 commit-to-hook gap or claim an atomic transaction across HTTP delivery and content writes.

## Mutation path

1. Set an emergency barrier with a unique event token.
2. Append an outbox row and mark DB state dirty in the mutation transaction.
3. After commit, monotonically advance affected generations.
4. Mark the outbox event applied and clear DB dirty state when no event remains.
5. Publish the clean runtime snapshot and clear only the matching barrier token.

On rollback, the transaction callback clears only its own token. It cannot clear a newer mutation's barrier.

Scheduled/CLI reconciliation captures an existing `event:<id>` barrier before replay and clears only that matching token once no outbox work remains. It also repairs a stranded event barrier when the database work was already applied. Control-plane recovery tokens are left for their owning reset operation, and newer event tokens are never cleared by an older replay.

After restoring a database backup, the restored runtime epoch must never be trusted while Redis may still contain responses from another point in time. `power-cache:restore-finalize --yes` therefore requires maintenance mode and `bypass`, holds or establishes the emergency barrier, reconciles restored outbox work, rotates the DB runtime epoch, resets every known generation, publishes a new runtime snapshot, and only then clears the barrier. A failure leaves the dirty barrier in place so traffic cannot reuse old responses.

## Failure matrix

| Failure | Serving behavior | Recovery |
|---|---|---|
| G7 cache store unavailable | origin BYPASS | automatic on next healthy request |
| generation key missing/invalid | HIT blocked | rotate DB runtime epoch and rebuild all known generations |
| barrier or runtime snapshot missing | HIT blocked | rotate epoch and rebuild control plane |
| process exits after DB commit | HIT blocked by dirty state/barrier | idempotent outbox reconciliation |
| cache-store write fails in a post-commit hook | durable DB dirty/outbox retained | immediate apply attempt, then reconciliation after store recovery |
| transaction rolls back | no generation change | matching token cleared by rollback callback |
| old event completes after a newer event | newer barrier remains | token compare-and-set prevents unsafe clear |
| direct SQL bypasses hooks | not detectable | operator must purge the affected scope or site |

## Trust boundaries

- G7 authentication, permission, IDV, locale, and approved middleware execute before cache delivery.
- The administrator-selected G7 cache store is untrusted for correctness; DB outbox/state is the durable authority.
- Cache payloads are revalidated before response construction.
- G7 7.0.9 hooks emitted after the content transaction commit leave a short core-level atomicity gap. This is the principal 1.0 blocker.
