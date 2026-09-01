#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${G7_ROOT:-}" ]]; then
    echo "G7_ROOT must point to a Gnuboard 7 checkout." >&2
    exit 2
fi

if [[ ! -x "${G7_ROOT}/vendor/bin/phpunit" ]]; then
    echo "${G7_ROOT}/vendor/bin/phpunit is not executable. Run composer install in G7 first." >&2
    exit 2
fi

exec "${G7_ROOT}/vendor/bin/phpunit" \
    --no-configuration \
    --bootstrap tests/bootstrap.php \
    tests
