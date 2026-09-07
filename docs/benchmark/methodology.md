# 성능 수용 기준과 측정 방법

## 핵심 목표

지정한 고비용 공개 경로에서 예열된 활성 모드는 우회 모드 대비 다음 조건을 모두 만족해야 합니다.

- p95 지연시간 20% 이상 감소
- 처리량 20% 이상 증가
- 오류율 0.1% 이하, 잘못된 응답과 개인화 응답 유출 0건

원본 우회 모드의 p95가 이미 150ms 미만인 경로는 개선 실익이 낮은 경로로 따로 관리합니다. 지연시간만을 근거로 캐시 범위를 확대하지 않습니다.

## 측정 절차

1. 우회·활성 모드에 동일한 호스트, 데이터, PHP-FPM 풀, DB, Redis, 네트워크 경로를 사용합니다.
2. 측정 전에 각 경로를 예열합니다.
3. 짧은 정합성 기본 검사를 먼저 수행한 뒤, 동시 요청 수 1·4·16·32에서 혼합 트래픽을 15~30분간 실행합니다.
4. p50/p95/p99, 초당 요청 수, 오류, PHP CPU·상주 메모리(RSS), DB 질의 수, Redis 명령·메모리·축출 수, 원본 응답 체크섬을 기록합니다.
5. 부하 중 지원되는 데이터 변경, 범위별 무효화, Redis 재시작, 특정 제어 키 삭제를 실행합니다.
6. 최소 3회 반복해 중앙값을 사용하고 원본 출력을 릴리스 근거와 함께 보관합니다.

지원 페이지·게시판 경로는 BYPASS와 HIT 모두 코어 `throttle:600,1` 미들웨어를 유지합니다. 두 경로는 같은 비회원 IP 제한 키를 공유하므로 단일 클라이언트 벤치마크는 합산 한도 아래에서 실행해야 합니다. HTTP 429를 애플리케이션 오류나 캐시 가속으로 해석하면 안 됩니다. 제공하는 측정 도구는 기본적으로 총 초당 요청 수 16건을 네 경로에 균등 분배하며, 제한이 적용되는 두 경로의 합계는 약 분당 480건입니다.

```bash
JWPC_BENCH_DURATION_SECONDS=900 \
JWPC_BENCH_WARMUP_SECONDS=10 \
JWPC_BENCH_RUNS=3 \
JWPC_BENCH_TARGET_RPS=16 \
tool/run-benchmark-matrix.sh /path/to/gnuboard7 https://target.example.com
```

이 실행 도구는 완료·중단 시 항상 플러그인을 `bypass`로 돌려놓습니다. 실행 중 모드를 변경하므로 명시적으로 승인된 대상에서만 사용하십시오. 이전 실행 결과가 있는 근거 폴더는 덮어쓰지 않습니다. 매 측정에 새 폴더를 사용하십시오.

승인된 운영 또는 운영과 동등한 원격 대상에는 SSH 실행 도구를 사용합니다. 원래 모드를 보존·복원하고 모드 실행 순서를 번갈아 적용하며, 혼합·경로별 비교를 출력합니다. 요청 빈도를 고정한 원격 실행은 내구성·정합성 근거이지 처리량 근거가 아닙니다.

```bash
JWPC_BENCH_REMOTE_APP_USER=remote-app-user \
JWPC_BENCH_CONCURRENCY=5 \
JWPC_BENCH_TARGET_RPS=8 \
tool/run-remote-benchmark-matrix.sh \
  ssh-target /absolute/g7/root https://target.example.com /new/evidence/path
```

첫 공개 원격 결과는 [g7devops.com 동시 사용자 5명 보고서](g7devops-live-5vu-2026-09-01.md)에 있습니다.

요청 빈도 고정 시험은 두 모드를 의도적으로 제한하므로 처리량 향상을 증명할 수 없습니다. 처리량은 격리된 벤치마크 환경에서 요청 수만 제한하고 전송 빈도 제한은 해제한 단기 시험으로 별도 측정합니다. 각 모드 시작 전 애플리케이션 캐시를 비워 공유 요청 제한기를 초기화하며, 운영 사이트의 오실행을 막기 위해 격리 환경임을 명시적으로 확인해야 합니다.

```bash
JWPC_BENCH_ISOLATED=1 \
JWPC_BENCH_CLEAR_RATE_LIMIT=1 \
JWPC_BENCH_TARGET_RPS=0 \
JWPC_BENCH_MAX_REQUESTS=400 \
JWPC_BENCH_DURATION_SECONDS=60 \
JWPC_BENCH_RUNS=3 \
tool/run-benchmark-matrix.sh /path/to/gnuboard7 http://127.0.0.1:18087
```

저비용 원본 경로가 처리량 개선 결과를 희석하지 않도록 지정한 고비용 경로도 네 경로 혼합 시험과 별도로 측정합니다.

```bash
JWPC_BENCH_ISOLATED=1 \
JWPC_BENCH_CLEAR_RATE_LIMIT=1 \
JWPC_BENCH_ROUTES=board \
JWPC_BENCH_TARGET_RPS=0 \
JWPC_BENCH_MAX_REQUESTS=400 \
JWPC_BENCH_RUNS=3 \
tool/run-benchmark-matrix.sh /path/to/gnuboard7 http://127.0.0.1:18087
```

모든 비교는 BYPASS와 ACTIVE의 경로별 SHA-256 응답 집합이 다르면 실패로 판정합니다. 원본 결과에 체크섬별 건수를 남겨 정합성 판정을 독립적으로 확인할 수 있게 합니다.

## 동시 부하·장애 주입 시험

격리된 G7 환경의 운영과 동등한 HTTP 처리 경로에서 데이터 변경·복구 기준을 검사합니다. 공식 `PageService`로 임시 공개 페이지를 만들고, 요청과 겹쳐 커밋된 변경·범위별 무효화를 실행한 뒤 `page:all` 세대 키 삭제와 Redis 재시작을 수행합니다. 마지막에는 임시 데이터를 제거합니다. 200이 아닌 응답, 오래된 토큰, 공개 응답의 개인화 필드, 아웃박스 진행 누락, 실행 세대 회전 누락, Redis 복구 실패, 불완전한 정리 중 하나라도 있으면 실패합니다.

Redis 컨테이너는 `AutoRemove=false`여야 합니다. 도구는 재시작이 영구 삭제로 이어지지 않도록 `docker run --rm` 컨테이너를 거부합니다. 정확한 컨테이너 이름을 검증하고 셸을 거치지 않고 Docker에 전달합니다.

```bash
JWPC_BENCH_ISOLATED=1 \
tool/run-fault-campaign.php \
  --g7-root=/path/to/gnuboard7 \
  --base-url=http://127.0.0.1:18088 \
  --redis-container=jwpc-test-redis \
  --duration=900 \
  --concurrency=16 \
  --rps=8 \
  --output=/new/evidence/path/fault-campaign.json
```

기본 초당 요청 수 8건은 동시 요청 묶음을 유지하면서 페이지 경로의 비회원 분당 600건 제한 아래에 머뭅니다. 릴리스 후보에서 동시 요청 수 1·4·16·32로 반복해야 하며, 15분 실행 한 번은 해당 동시 요청 수에 대한 근거일 뿐입니다.

2026-08-23 온라인 A/B는 방향성과 기본 동작을 확인한 결과이며 최종 베타 내구성 검증은 아닙니다. 다만 당시 게시판 경로는 오류 없이 핵심 지연시간·처리량 목표를 충족했습니다.
