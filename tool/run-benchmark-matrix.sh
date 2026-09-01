#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
g7_root="${1:-}"
base_url="${2:-}"
evidence_dir="${3:-${repo_root}/docs/benchmark/evidence/local}"
duration="${JWPC_BENCH_DURATION_SECONDS:-900}"
warmup="${JWPC_BENCH_WARMUP_SECONDS:-10}"
runs="${JWPC_BENCH_RUNS:-3}"
concurrencies="${JWPC_BENCH_CONCURRENCIES:-1 4 16 32}"
target_rps="${JWPC_BENCH_TARGET_RPS:-16}"
max_requests="${JWPC_BENCH_MAX_REQUESTS:-0}"
clear_rate_limit="${JWPC_BENCH_CLEAR_RATE_LIMIT:-0}"
route_names="${JWPC_BENCH_ROUTES:-page category-list category-detail board}"
php_bin="${PHP_BIN:-php}"

if [[ -z "${g7_root}" || -z "${base_url}" ]]; then
    echo "Usage: tool/run-benchmark-matrix.sh <g7-root> <base-url> [evidence-dir]" >&2
    exit 2
fi

if [[ ! -f "${g7_root}/artisan" ]]; then
    echo "G7 artisan not found: ${g7_root}/artisan" >&2
    exit 2
fi

if ! [[ "${duration}" =~ ^[0-9]+$ ]] || (( duration < 1 || duration > 3600 )); then
    echo "JWPC_BENCH_DURATION_SECONDS must be between 1 and 3600." >&2
    exit 2
fi

if ! [[ "${runs}" =~ ^[0-9]+$ ]] || (( runs < 1 || runs > 9 )); then
    echo "JWPC_BENCH_RUNS must be between 1 and 9." >&2
    exit 2
fi

if ! [[ "${target_rps}" =~ ^[0-9]+$ ]] || (( target_rps < 0 || target_rps > 10000 )); then
    echo "JWPC_BENCH_TARGET_RPS must be between 0 (unlimited) and 10000." >&2
    exit 2
fi

if ! [[ "${max_requests}" =~ ^[0-9]+$ ]] || (( max_requests < 0 || max_requests > 10000000 )); then
    echo "JWPC_BENCH_MAX_REQUESTS must be between 0 and 10000000." >&2
    exit 2
fi

if [[ "${clear_rate_limit}" == "1" && "${JWPC_BENCH_ISOLATED:-0}" != "1" ]]; then
    echo "JWPC_BENCH_CLEAR_RATE_LIMIT=1 requires JWPC_BENCH_ISOLATED=1." >&2
    exit 2
fi

if compgen -G "${evidence_dir}/run-*.json" >/dev/null \
    || [[ -e "${evidence_dir}/matrix-summary.json" ]]; then
    echo "Benchmark evidence directory already contains a prior run: ${evidence_dir}" >&2
    echo "Choose a new evidence directory; existing evidence is never overwritten." >&2
    exit 2
fi

mkdir -p "${evidence_dir}"

routes=()
for route_name in ${route_names}; do
    case "${route_name}" in
        page)
            routes+=("--route=page=/api/modules/sirsoft-page/pages/${JWPC_BENCH_PAGE_SLUG:-about}")
            ;;
        category-list)
            routes+=("--route=category-list=/api/modules/sirsoft-ecommerce/categories")
            ;;
        category-detail)
            routes+=("--route=category-detail=/api/modules/sirsoft-ecommerce/categories/${JWPC_BENCH_CATEGORY_SLUG:-clothing}")
            ;;
        board)
            routes+=("--route=board=/api/modules/sirsoft-board/boards/${JWPC_BENCH_BOARD_SLUG:-free}/posts")
            ;;
        *)
            echo "Unknown JWPC_BENCH_ROUTES entry: ${route_name}" >&2
            exit 2
            ;;
    esac
done

if (( ${#routes[@]} == 0 )); then
    echo "JWPC_BENCH_ROUTES must select at least one route." >&2
    exit 2
fi

set_mode() {
    (cd "${g7_root}" && "${php_bin}" artisan power-cache:mode "$1" --json >/dev/null)
}

run_one() {
    local mode="$1"
    local run="$2"
    local concurrency="$3"
    local output="${evidence_dir}/run-${run}-${mode}-c${concurrency}.json"

    set_mode "${mode}"
    if [[ "${clear_rate_limit}" == "1" ]]; then
        (cd "${g7_root}" && "${php_bin}" artisan cache:clear >/dev/null)
    fi
    if ! "${php_bin}" "${repo_root}/tool/run-mixed-load.php" \
        --base-url="${base_url}" \
        --label="run-${run}-${mode}-c${concurrency}" \
        --concurrency="${concurrency}" \
        --rps="${target_rps}" \
        --duration="${duration}" \
        --max-requests="${max_requests}" \
        --warmup="${warmup}" \
        "${routes[@]}" \
        --output="${output}" >/dev/null; then
        load_failed=1
    fi
}

trap 'set_mode bypass >/dev/null 2>&1 || true' EXIT INT TERM

comparison_failed=0
load_failed=0
for ((run = 1; run <= runs; run++)); do
    for concurrency in ${concurrencies}; do
        if (( run % 2 == 1 )); then
            run_one bypass "${run}" "${concurrency}"
            run_one active "${run}" "${concurrency}"
        else
            run_one active "${run}" "${concurrency}"
            run_one bypass "${run}" "${concurrency}"
        fi

        comparison="${evidence_dir}/run-${run}-comparison-c${concurrency}.json"
        bypass_result="${evidence_dir}/run-${run}-bypass-c${concurrency}.json"
        active_result="${evidence_dir}/run-${run}-active-c${concurrency}.json"
        if [[ ! -f "${bypass_result}" || ! -f "${active_result}" ]]; then
            comparison_failed=1
            continue
        fi
        comparison_status=0
        "${php_bin}" "${repo_root}/tool/compare-benchmarks.php" \
            "${bypass_result}" \
            "${active_result}" >"${comparison}" || comparison_status=$?
        if (( comparison_status >= 2 )); then
            comparison_failed=1
        fi
    done
done

matrix_summary="${evidence_dir}/matrix-summary.json"
matrix_status=0
"${php_bin}" "${repo_root}/tool/summarize-benchmark-matrix.php" \
    "${evidence_dir}" --output="${matrix_summary}" >/dev/null || matrix_status=$?
if (( matrix_status != 0 )); then
    comparison_failed=1
fi

set_mode bypass
trap - EXIT INT TERM

echo "Benchmark evidence: ${evidence_dir}"
exit "$((comparison_failed || load_failed))"
