#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

version="$(php -r '$manifest=json_decode(file_get_contents("plugin.json"), true, flags: JSON_THROW_ON_ERROR); echo $manifest["version"];')"
tag="${1:-v${version}}"

if [[ "${tag}" != "v${version}" ]]; then
    echo "Tag ${tag} does not match plugin.json version v${version}." >&2
    exit 1
fi

if ! rg -q "^## \\[${version//./\\.}\\]" CHANGELOG.md; then
    echo "CHANGELOG.md has no ${version} release entry." >&2
    exit 1
fi

rm -rf dist
mkdir -p dist
git archive --format=zip --prefix="jw-power_cache/" -o "dist/jw-power_cache-${version}.zip" HEAD
(
    cd dist
    shasum -a 256 "jw-power_cache-${version}.zip" > SHA256SUMS
)

echo "Built dist/jw-power_cache-${version}.zip"
