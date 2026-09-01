#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ssh_target="${1:-}"
remote_g7_root="${2:-}"
base_url="${3:-}"
evidence_dir="${4:-${repo_root}/docs/benchmark/evidence/remote}"
duration="${JWPC_BENCH_DURATION_SECONDS:-60}"
warmup="${JWPC_BENCH_WARMUP_SECONDS:-5}"
runs="${JWPC_BENCH_RUNS:-3}"
concurrency="${JWPC_BENCH_CONCURRENCY:-5}"
target_rps="${JWPC_BENCH_TARGET_RPS:-8}"
route_names="${JWPC_BENCH_ROUTES:-page category-list category-detail board}"
remote_app_user="${JWPC_BENCH_REMOTE_APP_USER:-}"
php_bin="${PHP_BIN:-php}"

if [[ -z "${ssh_target}" || -z "${remote_g7_root}" || -z "${base_url}" ]]; then
    echo "Usage: tool/run-remote-benchmark-matrix.sh <ssh-target> <remote-g7-root> <base-url> [evidence-dir]" >&2
    exit 2
fi

if ! [[ "${ssh_target}" =~ ^[A-Za-z0-9._@-]+$ ]]; then
    echo "Unsafe SSH target: ${ssh_target}" >&2
    exit 2
fi
if ! [[ "${remote_g7_root}" =~ ^/[A-Za-z0-9._/-]+$ ]]; then
    echo "Remote G7 root must be an absolute path containing only safe characters." >&2
    exit 2
fi
if [[ -z "${remote_app_user}" ]]; then
    echo "JWPC_BENCH_REMOTE_APP_USER must name the remote PHP-FPM application user." >&2
    exit 2
fi
if ! [[ "${remote_app_user}" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]]; then
    echo "Unsafe remote application user: ${remote_app_user}" >&2
    exit 2
fi
if ! [[ "${base_url}" =~ ^https?://[^[:space:]]+$ ]]; then
    echo "Base URL must be an HTTP(S) URL." >&2
    exit 2
fi

for value_name in duration warmup runs concurrency target_rps; do
    value="${!value_name}"
    if ! [[ "${value}" =~ ^[0-9]+$ ]]; then
        echo "${value_name} must be an integer." >&2
        exit 2
    fi
done
if (( duration < 1 || duration > 3600 || warmup > 300 || runs < 1 || runs > 9 || concurrency < 1 || concurrency > 256 || target_rps < 1 || target_rps > 10000 )); then
    echo "Benchmark numeric settings are outside the supported range." >&2
    exit 2
fi

if compgen -G "${evidence_dir}/run-*.json" >/dev/null; then
    echo "Benchmark evidence directory already contains a prior run: ${evidence_dir}" >&2
    exit 2
fi
mkdir -p "${evidence_dir}"

routes=()
for route_name in ${route_names}; do
    case "${route_name}" in
        page) routes+=("--route=page=/api/modules/sirsoft-page/pages/${JWPC_BENCH_PAGE_SLUG:-about}") ;;
        category-list) routes+=("--route=category-list=/api/modules/sirsoft-ecommerce/categories") ;;
        category-detail) routes+=("--route=category-detail=/api/modules/sirsoft-ecommerce/categories/${JWPC_BENCH_CATEGORY_SLUG:-clothing}") ;;
        board) routes+=("--route=board=/api/modules/sirsoft-board/boards/${JWPC_BENCH_BOARD_SLUG:-free}/posts") ;;
        *) echo "Unknown JWPC_BENCH_ROUTES entry: ${route_name}" >&2; exit 2 ;;
    esac
done

remote_artisan() {
    local command="$1"
    ssh "${ssh_target}" "cd '${remote_g7_root}' && sudo -u '${remote_app_user}' php artisan ${command}"
}

original_mode="$(remote_artisan 'power-cache:status --json' | "${php_bin}" -r '$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR); echo $d["mode"] ?? $d["settings"]["mode"] ?? "bypass";')"
if ! [[ "${original_mode}" =~ ^(active|observe|bypass)$ ]]; then
    echo "Unable to determine a safe original PowerCache mode." >&2
    exit 2
fi

set_mode() {
    remote_artisan "power-cache:mode $1 --json" >/dev/null
}

restore_mode() {
    set_mode "${original_mode}" >/dev/null 2>&1 || true
}
trap restore_mode EXIT INT TERM

run_one() {
    local mode="$1"
    local run="$2"
    local output="${evidence_dir}/run-${run}-${mode}-c${concurrency}.json"

    set_mode "${mode}"
    "${php_bin}" "${repo_root}/tool/run-mixed-load.php" \
        --base-url="${base_url}" \
        --label="run-${run}-${mode}-c${concurrency}" \
        --concurrency="${concurrency}" \
        --rps="${target_rps}" \
        --duration="${duration}" \
        --warmup="${warmup}" \
        "${routes[@]}" \
        --output="${output}" >/dev/null
}

comparison_failed=0
for ((run = 1; run <= runs; run++)); do
    if (( run % 2 == 1 )); then modes=(bypass active); else modes=(active bypass); fi
    for mode in "${modes[@]}"; do run_one "${mode}" "${run}"; done

    for route_name in mixed ${route_names}; do
        route_argument=()
        [[ "${route_name}" == "mixed" ]] || route_argument=("--route=${route_name}")
        comparison_status=0
        "${php_bin}" "${repo_root}/tool/compare-benchmarks.php" \
            "${evidence_dir}/run-${run}-bypass-c${concurrency}.json" \
            "${evidence_dir}/run-${run}-active-c${concurrency}.json" \
            "${route_argument[@]}" >"${evidence_dir}/run-${run}-comparison-${route_name}-c${concurrency}.json" || comparison_status=$?
        (( comparison_status < 2 )) || comparison_failed=1
    done
done

for route_name in mixed ${route_names}; do
    route_argument=()
    [[ "${route_name}" == "mixed" ]] || route_argument=("--route=${route_name}")
    summary_status=0
    "${php_bin}" "${repo_root}/tool/summarize-benchmark-matrix.php" \
        "${evidence_dir}" "${route_argument[@]}" \
        --output="${evidence_dir}/matrix-summary-${route_name}.json" >/dev/null || summary_status=$?
    (( summary_status == 0 )) || comparison_failed=1
done

restore_mode
trap - EXIT INT TERM
echo "Benchmark evidence: ${evidence_dir}"
exit "${comparison_failed}"
