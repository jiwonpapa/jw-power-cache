#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${repo_root}"

version="$(php -r '$manifest=json_decode(file_get_contents("plugin.json"), true, flags: JSON_THROW_ON_ERROR); echo $manifest["version"];')"
tag="${1:-v${version}}"
verify_only="${JWPC_RELEASE_VERIFY_ONLY:-0}"

if [[ "${tag}" != "v${version}" ]]; then
    echo "Tag ${tag} does not match plugin.json version v${version}." >&2
    exit 1
fi

if [[ "${verify_only}" != "0" && "${verify_only}" != "1" ]]; then
    echo "JWPC_RELEASE_VERIFY_ONLY must be 0 or 1." >&2
    exit 2
fi

if [[ "${verify_only}" != "1" ]]; then
    if ! git rev-parse --verify --quiet "refs/tags/${tag}^{commit}" >/dev/null; then
        echo "Release tag does not exist locally: ${tag}" >&2
        exit 1
    fi
    tag_commit="$(git rev-parse "refs/tags/${tag}^{commit}")"
    head_commit="$(git rev-parse HEAD)"
    if [[ "${tag_commit}" != "${head_commit}" ]]; then
        echo "Release tag ${tag} does not point to HEAD ${head_commit}." >&2
        exit 1
    fi
    if ! git diff --quiet || ! git diff --cached --quiet; then
        echo "Release build requires a clean tracked worktree." >&2
        exit 1
    fi
fi

if ! rg -q "^## \\[${version//./\\.}\\]" CHANGELOG.md; then
    echo "CHANGELOG.md has no ${version} release entry." >&2
    exit 1
fi

rm -rf dist
mkdir -p dist
archive="dist/jw-power_cache-${version}.zip"
archive_root="jw-power_cache"
git archive --format=zip --prefix="${archive_root}/" -o "${archive}" HEAD

entries="$(unzip -Z1 "${archive}")"
for forbidden in \
    "${archive_root}/.github/" \
    "${archive_root}/tests/" \
    "${archive_root}/tool/" \
    "${archive_root}/dist/" \
    "${archive_root}/.env" \
    "${archive_root}/.git" \
    "${archive_root}/vendor/"; do
    if printf '%s\n' "${entries}" | rg -Fq "${forbidden}"; then
        echo "Release archive contains forbidden path: ${forbidden}" >&2
        exit 1
    fi
done

for required in \
    "${archive_root}/plugin.json" \
    "${archive_root}/plugin.php" \
    "${archive_root}/composer.json" \
    "${archive_root}/config/power_cache.php" \
    "${archive_root}/database/migrations/2026_08_23_000001_create_jw_power_cache_tables.php"; do
    if ! printf '%s\n' "${entries}" | rg -Fxq "${required}"; then
        echo "Release archive is missing required runtime file: ${required}" >&2
        exit 1
    fi
done

archive_version="$(unzip -p "${archive}" "${archive_root}/plugin.json" | php -r '$manifest=json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR); echo $manifest["version"];')"
if [[ "${archive_version}" != "${version}" ]]; then
    echo "Archive manifest version ${archive_version} does not match ${version}." >&2
    exit 1
fi

unzip -t "${archive}" >/dev/null
(
    cd dist
    shasum -a 256 "jw-power_cache-${version}.zip" > SHA256SUMS
)

if [[ "${verify_only}" == "1" ]]; then
    echo "Verified dry-run dist/jw-power_cache-${version}.zip"
else
    echo "Built release dist/jw-power_cache-${version}.zip from ${tag}"
fi
