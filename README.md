# JW PowerCache

JW PowerCache는 **검증된 비회원 공개 JSON API**를 세대 기반 무효화로 가속하는 Gnuboard 7 플러그인입니다. 데이터 신선도를 TTL에 맡기지 않고, 변경 훅이 실행되면 내구성 있는 outbox를 기록한 뒤 캐시 세대를 회전합니다.

현재 버전은 `0.3.0-beta.1 Open Source Beta`입니다. 제품명은 **JW PowerCache**, G7 플러그인 식별자는 `jw-power_cache`입니다. G7 7.0.10의 공식 동일 트랜잭션 mutation 훅 계약을 요구합니다.

## 현재 지원 범위

| 구분 | 지원 |
|---|---|
| 공개 페이지 상세 | `api.modules.sirsoft-page.pages.show` |
| 쇼핑몰 공개 카테고리 | `categories.index`, `categories.show` |
| 공개 게시판 목록 | 비회원 read 권한 게시판의 1~3페이지, `per_page` 최대 50 |
| 인증 요청 | 항상 BYPASS |
| 쿠키·미등록 query·미등록 route middleware | 항상 BYPASS |
| 게시글 상세·상품·검색·장바구니·주문 | 현재 캐시하지 않음 |
| 응답 형식 | 200 JSON, 크기 상한 이내 |
| 저장 금지 | Set-Cookie, no-store, redirect, 인증/다운로드 헤더, 미지원 Vary |

카테고리 트리에 공개 상품 수가 포함되므로 카테고리뿐 아니라 상품 생성·수정·삭제·일괄 변경도 `category:tree` 세대를 회전합니다.

게시판 목록은 원본 `permission:user,sirsoft-board.{slug}.posts.read`와 같은 `GuestRoleResolver`로 비회원 권한을 먼저 확인합니다. 글·댓글·첨부·게시판 설정·권한·작성자 표시가 바뀌면 `board:all` 세대를 즉시 회전합니다. `created_at_formatted`, `is_new`, 조회수처럼 DB 쓰기 없이도 표시가 변하는 값만 60초 시계 버킷으로 제한하며, 콘텐츠 신선도는 계속 변경 훅 무효화가 담당합니다. PC/모바일 `per_page` 차이는 별도 키로 격리합니다.

## 정합성 모델

1. 지원 콘텐츠·사용자 표시 쓰기 경로의 변경 리스너는 `sync: true, transactional: true`로 원본 변경 트랜잭션 안에서 실행됩니다.
2. 같은 트랜잭션에 outbox와 `dirty_event_id`를 기록하므로 리스너 실패 시 원본 변경까지 함께 롤백됩니다.
3. 커밋 뒤 outbox ID를 세대 값으로 적용합니다.
4. 정상 HIT는 전용 저장소의 clean runtime snapshot, 비상 장벽, 현재 세대 벡터를 모두 통과해야 합니다. DB state는 snapshot 최초 생성·dirty 복구·운영 진단 때만 읽습니다.
5. Redis/file 오류가 나면 원본 컨트롤러로 fail-open하고 캐시 때문에 5xx를 만들지 않습니다.
6. 미적용 outbox가 하나라도 있으면 복구 전까지 모든 HIT를 금지합니다.

변경 감지 시 DB 커밋 전에 전용 저장소의 비상 장벽을 먼저 세우고, 커밋 후 세대 적용·outbox 완료·clean snapshot 반영이 모두 끝난 뒤 장벽을 내립니다. 이 때문에 평상시 HIT는 파워캐시 자체 DB 질의를 만들지 않으면서도, 커밋 직후 프로세스 종료나 저장소 적용 실패 때는 이전 응답을 제공하지 않습니다. 정상 rollback은 해당 이벤트 토큰의 장벽만 자동 해제하며, 더 최신 이벤트의 장벽은 해제할 수 없습니다.

Redis eviction이나 운영 실수로 barrier, snapshot, generation 키 하나만 사라져도 값 `0`으로 간주하지 않습니다. 모든 HIT를 막고 DB의 runtime epoch를 회전한 뒤 알려진 전체 generation 제어면을 재구축하므로, 물리적으로 남은 과거 응답은 새 키 공간에서 도달할 수 없습니다.

G7 코어가 동일 트랜잭션 훅 capability를 제공하지 않으면 doctor가 실패하고 active 모드도 `core_transactional_hooks` 사유로 HIT를 차단합니다. 정확한 7.0.10 transaction-seam 커밋과의 원자적 commit/rollback, 장애 캠페인, 백업 복구는 검증했지만 공식 코어 릴리스 또는 별도 지원 패키지가 아직 없어 현재 버전은 Open Source Beta입니다.

사이트 전역 설정과 모듈·플러그인·템플릿·언어팩 생명주기는 아직 일반 after 훅 경계입니다. 이 관리 작업은 `bypass` 전환 → 작업 수행 → `purge --scope=site` → doctor → `active` 순서의 유지보수 절차를 적용해야 합니다.

`retention_seconds`는 오래된 물리 엔트리를 회수하기 위한 백엔드 보존시간입니다. 데이터 신선도 TTL이 아닙니다. 세대가 바뀐 엔트리는 보존시간이 남아도 즉시 MISS입니다.

## 설치

관리자 플러그인 설치 화면에서 다음 GitHub 저장소 URL을 입력합니다.

```text
https://github.com/jiwonpapa/jw-power-cache
```

공식 G7 7.0.10 전 Beta 평가는 이동 가능한 브랜치 이름만 믿지 말고 검증된 코어 커밋을 정확히 고정하십시오.

```bash
git clone --branch codex/power-cache-transaction-seam \
  https://github.com/jiwonpapa/gnuboard7.git gnuboard7-power-cache
cd gnuboard7-power-cache
git checkout --detach 7d628dc4e57153a6217372a8a4bf8ea2904c680f
```

다른 G7 커밋에서는 `power-cache:doctor`가 동일 트랜잭션 훅 capability를 확인하기 전까지 `active`로 전환하지 마십시오.

개발 환경에서 번들 소스로 설치하려면 저장소를 G7의 `_bundled` 디렉터리에 복제합니다.

먼저 운영 저장소를 선택하고 환경변수를 적용합니다. 기본 file 드라이버를 사용할 때는 아래의 단일 노드 조건을 확인한 뒤 `JW_POWER_CACHE_FILE_SINGLE_NODE=true`를 설정해야 최초 `doctor`가 제어면을 안전하게 초기화합니다. 다중 노드는 아래 Redis 설정을 먼저 적용하십시오.

```bash
git clone https://github.com/jiwonpapa/jw-power-cache.git plugins/_bundled/jw-power_cache
php artisan extension:update-autoload
php artisan plugin:install jw-power_cache --vendor-mode=bundled
php artisan plugin:activate jw-power_cache
php artisan power-cache:doctor
```

설치 기본 모드는 `observe`입니다. 이 모드는 적합성만 검사하고 응답을 저장하거나 HIT로 제공하지 않습니다. 저장소 환경변수 적용 후 doctor를 통과한 뒤 `php artisan power-cache:mode active` 또는 관리자 플러그인 설정에서 `active`로 바꿉니다.

### 단일 노드 file 저장소

file 드라이버로 실제 HIT를 허용하려면 단일 PHP 노드라는 운영 확인이 필요합니다.

```dotenv
JW_POWER_CACHE_FILE_SINGLE_NODE=true
```

확인이 없으면 `active`여도 복구 장벽이 `file_single_node_unacknowledged`로 HIT를 차단합니다.
file 경로를 기본 `storage/app/jw-power-cache/cache` 밖으로 옮길 경우 만료 파일 GC가 다른 디렉터리를 건드리지 않도록 `JW_POWER_CACHE_FILE_GC_SAFE_ROOT`를 전용 상위 디렉터리로 명시해야 합니다. 안전 루트 밖이면 GC는 삭제하지 않습니다.

### Redis 저장소

운영·다중 노드는 세션·큐·기본 캐시와 분리된 Redis DB를 사용하십시오.

```dotenv
JW_POWER_CACHE_REDIS_HOST=127.0.0.1
JW_POWER_CACHE_REDIS_PORT=6379
JW_POWER_CACHE_REDIS_PASSWORD=
JW_POWER_CACHE_REDIS_DB=7
JW_POWER_CACHE_REDIS_PREFIX=gnuboard7:jwpc:
```

플러그인은 `FLUSHDB`, 태그 인덱스, 전체 key scan으로 무효화하지 않습니다. 세대만 회전하고 물리 엔트리는 retention으로 회수합니다.

제어 키 유실은 안전하게 복구되지만 반복적인 eviction은 MISS 급증과 epoch 회전을 일으킵니다. 운영 Redis는 전용 DB/인스턴스, 충분한 `maxmemory`, eviction·memory·latency 경보를 갖추십시오.

## 운영 명령

```bash
php artisan power-cache:doctor
php artisan power-cache:status
php artisan power-cache:mode bypass
php artisan power-cache:mode observe
php artisan power-cache:mode active
php artisan power-cache:purge --scope=site --reason=deploy
php artisan power-cache:purge --scope=page --reason=page-import
php artisan power-cache:purge --scope=category --reason=product-import
php artisan power-cache:purge --scope=board --reason=board-import
php artisan power-cache:reconcile --limit=100
php artisan power-cache:gc --days=7
php artisan power-cache:restore-finalize --yes
```

- `doctor`: 저장소 read/write, DB state/outbox, route·middleware 계약, Redis DB 격리·eviction 정책·누적 eviction을 검사합니다.
- `status`: 현재 모드와 dirty/outbox 상태를 조회합니다.
- `mode`: `bypass`(OFF), `observe`(저장 없이 판정), `active`(ON)를 전환합니다. `active`는 doctor가 실패하면 전환 자체를 차단합니다.
- `purge`: key 삭제 없이 `site`, `page:all`, `category:tree`, `board:all` 세대를 회전합니다.
- `reconcile`: 중복·역순 실행에도 안전하게 미적용 outbox를 재생합니다.
- `gc`: 적용 완료된 오래된 outbox 감사 이력과 file 저장소의 만료 물리 파일을 정리합니다. 미적용 행과 캐시 신선도에는 영향을 주지 않습니다. Redis 물리 만료는 Redis 자체 TTL이 담당합니다.
- `restore-finalize`: 유지보수 모드와 `bypass`에서만 실행되며, 복구된 outbox를 정리한 뒤 runtime epoch를 회전하고 전체 제어면을 재구축합니다. `--yes`가 없거나 사이트가 온라인이면 변경하지 않습니다.

더미 생성기, importer, seed, 외부 SQL처럼 공식 Service 훅을 우회하는 변경 뒤에는 반드시 해당 scope purge를 실행해야 합니다. 변경 범위를 모르면 `--scope=site`를 사용하십시오.

활성 플러그인은 `reconcile --limit=100`을 매분 예약해 저장소 장애 뒤 남은 outbox를 자동 재생하며, 일일 GC는 적용 완료된 감사 이력만 정리합니다. 서버의 Laravel scheduler가 실제로 실행 중이어야 합니다.

## 백업 복구 순서

백업에는 G7 전체 데이터베이스, `storage/app/plugins/jw-power_cache/settings/setting.json`, 설치한 플러그인 ZIP과 체크섬을 함께 보관하십시오. Redis는 원본 데이터가 아니므로 백업본을 복원하지 않습니다.

```bash
php artisan power-cache:mode bypass
php artisan down --retry=60
# queue worker를 멈춘 뒤 DB, 설정 파일, 플러그인 코드를 복구
php artisan power-cache:restore-finalize --yes
php artisan power-cache:doctor
php artisan up
```

`restore-finalize`가 실패하면 유지보수 모드를 해제하지 마십시오. 비상 dirty 장벽, 미적용 outbox, Redis 연결을 먼저 확인해야 합니다. 격리 환경 연습 도구는 정확한 DB 이름과 명시적 파괴 허용값을 모두 요구합니다.

```bash
G7_ROOT=/path/to/isolated-g7 \
JWPC_RESTORE_DRILL_ALLOW_DESTRUCTIVE=1 \
JWPC_RESTORE_DRILL_EXPECT_DATABASE=isolated_database \
php tool/run-backup-restore-drill.php
```

## 보안 불변식

다음 항목은 관리자 설정으로 완화할 수 없습니다.

- GET/HEAD 및 정확한 route allowlist만 허용
- Authorization/Proxy-Authorization와 민감 토큰 헤더가 있으면 BYPASS
- 사용자 객체나 쿠키가 하나라도 있으면 BYPASS
- route 정책에 없는 query/middleware가 있으면 BYPASS
- 게시판 목록은 원본과 같은 guest read 권한 선검증 실패 시 BYPASS
- 게시판 목록은 1~3페이지·`per_page` 최대 50만 허용하고 PC/모바일 키를 분리
- 같은 route에 다른 before_core/after_core 확장 미들웨어가 겹치면 BYPASS
- 공개 응답을 변형하는 origin filter hook이 등록되어 있으면 BYPASS
- 저장 전후 세대가 다르면 미저장
- HEAD MISS는 원본만 호출하고 캐시를 생성하지 않음
- Set-Cookie/no-store/인증·다운로드·redirect 응답은 미저장
- 세대 확인이나 복구 장벽 확인이 실패하면 stale 응답 제공 금지

기본 Laravel JSON 응답의 `private, no-cache`는 브라우저 캐시 정책으로 보존하되, 서버 내부 origin cache 저장 자체를 막지는 않습니다. `no-store`만 절대 저장 금지입니다.

## 알려진 제한

- 현재 실제 HIT 지원은 페이지 상세, 카테고리 API, 공개 비회원 게시판 hot-list입니다.
- 캐시 HIT는 extension `api, after_core` 지점에서 반환됩니다. Laravel 미들웨어 우선순위상 `optional.sanctum`과 throttle은 HIT 전에도 실행되며, 게시판 route permission은 뒤쪽에 있어 플러그인이 같은 `GuestRoleResolver`로 먼저 검사합니다. 이 순서를 doctor의 정확한 middleware 계약으로 고정합니다.
- `after_core` 앞에서 실행되는 코어 API 미들웨어와 rate-limit 비용은 남습니다. runtime barrier와 페이지·카테고리 HIT는 플러그인 DB를 읽지 않지만, 게시판은 guest role/permission을 요청당 한 번 읽습니다. 전체 HTTP 요청을 0-query로 만들려면 인증·권한·IDV·rate-limit 뒤/컨트롤러 앞의 공식 코어 seam 또는 PHP 부팅 전 서버 어댑터가 필요합니다.
- 직접 SQL 변경을 자동 감지할 수 없습니다.
- 공식 G7 7.0.10 릴리스 전에는 transaction seam 브랜치가 필요합니다.
- 사이트 전역 설정·확장 생명주기는 아직 동일 트랜잭션 seam 대상이 아니므로 유지보수 중 bypass와 완료 후 site purge가 필요합니다.
- 다중 노드 file 저장소는 지원하지 않습니다.
- 전체 페이지 HTML, 바이너리, 검색 결과, 사용자별 응답은 지원하지 않습니다.
- PHP/Laravel 부팅 전 캐시는 별도 서버 어댑터 범위입니다.

## 검증

Gnuboard 7 루트를 지정해 독립 테스트를 실행합니다.

```bash
G7_ROOT=/path/to/gnuboard7 \
  /path/to/gnuboard7/vendor/bin/phpunit --no-configuration \
  --bootstrap tests/bootstrap.php tests
```

현재 독립 테스트는 실제 MySQL·Redis 기준 **51 tests / 440 assertions**이며 guest 격리, 코어 호환성 fail-close, 게시판 read 권한·페이지 범위·PC/모바일 변형, 변경 훅 커버리지, 응답 저장 금지, 변조·구형 저장물 거부, 설정·스케줄 계약, 세대 단조성, 제어 키 선택 유실, 충돌 토큰, 분산 락, Redis eviction 진단, 정상 HIT의 플러그인 DB query 0, MISS→HIT, 원본 변경과 outbox의 동일 트랜잭션 commit/rollback, 장벽 기록 실패의 outbox 보존, 저장소 장애와 outbox 자동 재생, 복구 후 epoch 회전·제어면 재구축, 벤치마크 체크섬·중앙값 판정을 검증합니다. CI는 PHP 8.2/8.5, 정확히 고정한 G7 transaction-seam 커밋, Redis 7.4, MySQL 8.4, MariaDB 11.4를 검사합니다.

실서버 ON/OFF 결과는 [온라인 ON/OFF 실측 보고서](docs/benchmark/jw-power-cache-live-ab-report-2026-08-23.md)에 기록되어 있습니다.

Redis 로컬 재현 환경의 3회 중앙값 게시판 성능 결과는 [로컬 Beta 성능 보고서](docs/benchmark/local-beta-performance-2026-09-01.md)에 기록되어 있습니다. 15분 FPM 내구성·장애 주입 결과는 [장애 캠페인 보고서](docs/benchmark/local-fpm-fault-campaign-2026-09-01.md)에 기록되어 있습니다.

G7 7.0.10 후보의 클린 설치·활성화·비활성화·데이터 제거·재설치 결과는 [클린 수명주기 검증 보고서](docs/verification/clean-lifecycle-2026-09-01.md)에 기록되어 있습니다.

관리자 설정 실브라우저 결과는 [관리자 설정 검증 보고서](docs/verification/admin-settings-browser-2026-09-01.md), 실제 백업 복구와 릴리스 롤백 결과는 [복구·롤백 검증 보고서](docs/verification/backup-restore-release-rollback-2026-09-01.md)에 기록되어 있습니다.

제품화 기준과 운영 문서는 [로드맵](ROADMAP.md), [아키텍처·장애 모델](docs/architecture.md), [성능 검증 방법](docs/benchmark/methodology.md), [릴리스 체크리스트](docs/release-checklist.md), [보안 정책](SECURITY.md), [지원 범위](SUPPORT.md)에서 확인할 수 있습니다.
