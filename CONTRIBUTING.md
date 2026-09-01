# Contributing

## Development setup

JW PowerCache is tested against a separate Gnuboard 7 checkout.

```bash
git clone https://github.com/gnuboard/g7.git /path/to/g7
composer install --working-dir=/path/to/g7 --no-scripts
G7_ROOT=/path/to/g7 tool/test.sh
```

Set `JW_POWER_CACHE_TEST_REDIS_URL` to include the real Redis integration tests. The CI matrix covers PHP 8.2/8.5, G7 7.0.8/7.0.9, Redis 7.4, MySQL 8.4, and MariaDB 11.4.

Before opening a pull request, run:

```bash
composer validate --strict --no-check-publish
/path/to/g7/vendor/bin/pint --test src tests database plugin.php
G7_ROOT=/path/to/g7 JW_POWER_CACHE_TEST_REDIS_URL=redis://127.0.0.1:6379/15 tool/test.sh
```

## Change rules

- Preserve fail-closed cache correctness. Store or Redis errors may bypass caching, but may not serve stale or personalized data.
- Add a regression test for every correctness fix.
- Do not broaden route, query, middleware, header, or cookie allowlists without a threat and compatibility analysis.
- Document measurable performance claims with raw conditions and before/after results.
- Keep commits focused and do not include credentials, production dumps, or generated release archives.
