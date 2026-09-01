# Architecture and failure model

## Request path

Eligible guest requests pass an explicit route and presentation contract. Active mode reads a clean control barrier, runtime snapshot, generation vector, and cached response. Any missing, malformed, dirty, or mismatched state becomes a MISS/BYPASS; it is never interpreted as generation zero.

## Mutation path

1. Set an emergency barrier with a unique event token.
2. Append an outbox row and mark DB state dirty in the mutation transaction.
3. After commit, monotonically advance affected generations.
4. Mark the outbox event applied and clear DB dirty state when no event remains.
5. Publish the clean runtime snapshot and clear only the matching barrier token.

On rollback, the transaction callback clears only its own token. It cannot clear a newer mutation's barrier.

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
