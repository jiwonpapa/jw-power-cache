# g7devops.com 실서버 5VU 캐시 비교 벤치마크

## 결론

JW PowerCache `0.3.0-beta.1`을 g7devops.com에 배포하고 공개 HTTPS 경로에서 BYPASS와 ACTIVE를 비교했다. 5개 동시 사용자, 총 8 RPS, 4개 주요 API, 모드별 3회 중앙값 기준으로 모든 경로의 p95가 49.0~68.9% 개선됐다. 총 2,880건이 모두 HTTP 200이었고 오류·429·응답 체크섬 불일치는 없었다.

| 경로 | BYPASS p95 | ACTIVE p95 | 개선 | 판정 |
|---|---:|---:|---:|---|
| 4경로 혼합 | 538.300ms | 208.021ms | 61.4% | PASS |
| 페이지 상세 | 408.205ms | 208.021ms | 49.0% | PASS |
| 카테고리 목록 | 576.930ms | 179.629ms | 68.9% | PASS |
| 카테고리 상세 | 453.642ms | 182.712ms | 59.7% | PASS |
| 게시판 목록 | 568.169ms | 256.659ms | 54.8% | PASS |

## 정확성 및 캐시 결과

- 측정 요청: BYPASS 1,440건 + ACTIVE 1,440건 = 총 2,880건
- HTTP 200: 2,880건, 오류 및 429: 0건
- ACTIVE: HIT 1,436건, 정상 최초 저장 `MISS-STORED` 4건
- 네 경로 모두 BYPASS/ACTIVE 응답 SHA-256 집합 일치
- 각 모드는 60초 측정 전 5초 워밍업을 수행했고, 순서 편향을 줄이기 위해 홀수 회차는 BYPASS→ACTIVE, 짝수 회차는 ACTIVE→BYPASS로 실행했다.

## 서버 부하의 방향성

세 번의 모드별 구간에서 수집한 서버 전역 counter 차이의 중앙값은 다음과 같다.

| 지표 | BYPASS | ACTIVE | 변화 |
|---|---:|---:|---:|
| PHP-FPM CPU 누적 증가 | 44.820초 | 32.696초 | 27.1% 감소 |
| MySQL Questions 증가 | 6,187 | 3,792 | 38.7% 감소 |
| Redis command 증가 | 29,703 | 30,711 | 3.4% 증가 |
| Slow query 증가 | 0 | 0 | 동일 |

서버 전역 counter에는 같은 시간대의 다른 요청도 포함될 수 있으므로 요청당 절대 사용량이 아니라 방향성 근거로만 사용한다.

## 실제 환경과 데이터

- 대상: `https://www.g7devops.com`, Nginx 1.24.0, PHP-FPM 8.5.9
- 서버: 2 vCPU, 약 1.86GiB RAM, MySQL 8.4.10, Redis 7.0.15
- 데이터: 게시글 50,014건, 댓글 62,853건, 카테고리 200건, 상품 20,000건
- 경로: 페이지 `about`, 카테고리 목록, 카테고리 `bmj-10-category-001`, 게시판 `freebd`
- 배포: JW PowerCache 0.3.0-beta.1 ACTIVE, G7 transaction seam `7d628dc4e57153a6217372a8a4bf8ea2904c680f`

## 변동성과 해석 제한

3회 중앙값은 모든 경로에서 20% p95 개선 기준을 통과했다. 다만 3회차 개별 결과는 혼합 17.7%, 페이지 5.2%, 게시판 10.1%로 기준에 미달했고 카테고리 목록·상세는 각각 34.9%, 30.8%였다. 외부 HTTPS·공유 VM의 순간 변동이 있으므로 단일 회차 수치를 제품 보장치로 사용하면 안 된다.

페이지 p99 중앙값은 754.546ms에서 767.433ms로 1.7% 악화됐다. p95와 서버 CPU·DB 부하는 개선됐지만 극단 꼬리 지연 최적화는 후속 과제다.

이 시험은 의도적으로 총 8 RPS로 고정했으므로 처리량 향상을 증명하지 않는다. 처리량 근거는 별도의 격리 환경 uncapped 시험을 사용하고, 실서버에서는 안정성과 정확성 중심으로 해석한다.

## 재현과 원시 증거

원시 JSON, 회차별 판정, 중앙값 요약, 서버 telemetry는 [`evidence/2026-09-01-live-5vu`](evidence/2026-09-01-live-5vu/)에 보존한다.

```bash
JWPC_BENCH_REMOTE_APP_USER=g7devops \
JWPC_BENCH_PAGE_SLUG=about \
JWPC_BENCH_CATEGORY_SLUG=bmj-10-category-001 \
JWPC_BENCH_BOARD_SLUG=freebd \
JWPC_BENCH_DURATION_SECONDS=60 \
JWPC_BENCH_WARMUP_SECONDS=5 \
JWPC_BENCH_RUNS=3 \
JWPC_BENCH_CONCURRENCY=5 \
JWPC_BENCH_TARGET_RPS=8 \
tool/run-remote-benchmark-matrix.sh \
  g7devops /home/g7devops/public_html https://www.g7devops.com \
  docs/benchmark/evidence/new-live-run
```

이 도구는 원래 운영 모드를 확인해 종료·중단 시 복구하며 기존 증거 디렉터리를 덮어쓰지 않는다. 실서버 모드를 변경하므로 승인된 대상에서만 실행해야 한다.
