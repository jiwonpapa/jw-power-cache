# Support

Use GitHub Issues for reproducible bugs and feature requests. Include JW PowerCache, G7, PHP, database, cache driver, and Redis versions plus sanitized `power-cache:doctor --json` output.

Community support is best-effort. Commercial response times, installation, production tuning, and incident assistance require a separate support agreement. Security reports must follow [SECURITY.md](SECURITY.md), not public issues.

The 0.3 beta line supports official G7 7.0.9 or later with Page 1.1.0, Board 1.1.0, and Ecommerce 1.2.0 or later. JW PowerCache uses the standard file, Redis, or Memcached store selected by the G7 administrator through `CacheInterface`; it does not register a private store. Multi-node deployments must select a shared store in G7.

Authenticated caching is supported only for public board lists and is isolated by authenticated user ID. Other personalized or authenticated responses, multi-node file cache, direct SQL changes without an explicit purge, modified route middleware contracts, and platforms outside the compatibility matrix remain unsupported. G7 7.0.9 post-commit hooks retain a short crash window and therefore do not provide the stronger atomic guarantee of an in-transaction hook seam.

Loading UX supports the official `sirsoft-basic` and `sirsoft-admin_basic` transition overlays. Four audited large-content spinner profiles are included; action and modal spinners are intentionally excluded. Third-party templates receive transition-overlay-only compatibility until an explicit profile is added. The runtime path uses no component registry or private G7 API and was source-checked across G7 tags 7.0.0 through 7.0.9.
