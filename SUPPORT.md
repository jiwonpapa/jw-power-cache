# Support

Use GitHub Issues for reproducible bugs and feature requests. Include JW PowerCache, G7, PHP, database, cache driver, and Redis versions plus sanitized `power-cache:doctor --json` output.

Community support is best-effort. Commercial response times, installation, production tuning, and incident assistance require a separate support agreement. Security reports must follow [SECURITY.md](SECURITY.md), not public issues.

The 0.3 beta line requires G7 7.0.10 and Page 1.1.1, Board 1.1.1, and Ecommerce 1.2.1 or later. Until G7 7.0.10 is officially released, evaluation support is limited to [`jiwonpapa/gnuboard7` commit `7d628dc4e57153a6217372a8a4bf8ea2904c680f`](https://github.com/jiwonpapa/gnuboard7/commit/7d628dc4e57153a6217372a8a4bf8ea2904c680f) on `codex/power-cache-transaction-seam`. Pin the commit; do not rely on a moving branch head.

Unsupported cases include personalized or authenticated response caching, multi-node file cache, direct SQL changes without an explicit purge, modified route middleware contracts, and platforms outside the compatibility matrix.

Loading UX supports the official `sirsoft-basic` and `sirsoft-admin_basic` transition overlays. Four audited large-content spinner profiles are included; action and modal spinners are intentionally excluded. Third-party templates receive transition-overlay-only compatibility until an explicit profile is added. The runtime path uses no component registry or private G7 API and was source-checked across G7 tags 7.0.0 through 7.0.9.
