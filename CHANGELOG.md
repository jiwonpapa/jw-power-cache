# Changelog

## [Unreleased]

### Added

- 고정 RPS 내구성 및 요청 수 제한 무제한 성능 매트릭스를 실행하는 재현 가능한 혼합·지정 경로 부하 도구
- 3회 중앙값, 동시성별 p95·처리량·오류율, BYPASS/ACTIVE 경로별 응답 체크섬을 fail-closed 판정하는 벤치마크 요약기

### Changed

- 실제 미들웨어 실행 순서에 맞춰 HIT에서도 optional auth·throttle이 유지되는 운영 제한을 명확화

## [0.3.0-alpha.2] - 2026-09-01

### Added

- G7 7.0.10 동일 트랜잭션 mutation 훅 연동과 코어 capability 진단
- 원본 변경, 무효화 outbox, 세대 적용의 실제 MySQL·Redis commit/rollback 통합 테스트

### Changed

- 페이지·카테고리·상품·게시판·사용자 표현 변경 리스너를 동일 트랜잭션 전용 훅으로 전환
- G7 최소 버전을 7.0.10, 페이지·게시판·이커머스 모듈 최소 버전을 transaction seam 포함 버전으로 상향

### Fixed

- 지원하지 않는 G7 코어에서 active 모드가 stale 가능성을 안고 동작하지 않도록 HIT를 fail-closed 우회

## [0.3.0-alpha.1] - 2026-09-01

### Added

- 실제 Redis 제어 키 유실·분산 락 통합 테스트
- PHP/G7/Redis 및 MySQL 8.4/MariaDB 11.4 CI 매트릭스
- 태그·버전·변경이력 검증, SHA-256 및 build provenance가 포함된 릴리스 파이프라인
- 보안 신고, 지원, 기여, 아키텍처 장애 모델, 성능 수용 기준, Beta/1.0 출시 게이트
- doctor의 Redis maxmemory/eviction 정책 및 누적 eviction 진단
- 미적용 outbox를 매분 재생하는 자동 reconcile 스케줄

### Fixed

- Redis eviction 등으로 generation 키가 사라질 때 `0`으로 되돌아가 과거 응답이 다시 유효해질 수 있던 제어면 결함
- 정상 트랜잭션 rollback 뒤 비상 장벽이 수동 purge 전까지 남던 문제
- 오래된 이벤트 완료가 더 최신 dirty 장벽을 해제할 수 있는 경쟁 조건
- dirty snapshot 자동 복구가 유효성 검사 순서 때문에 실행되지 않던 문제
- 콘텐츠가 이미 커밋된 훅에서 장벽 기록 실패가 DB outbox까지 rollback해 복구 근거를 잃던 문제

### Changed

- 제품명을 `JW PowerCache`로 확정하고 독립 저장소로 분리
- G7 플러그인 식별자를 `jw-power_cache`, PHP 네임스페이스를 `Plugins\\Jw\\PowerCache`로 변경
- 설정, 테이블, 저장소 경로, 환경변수 및 진단 헤더의 브랜드 접두사를 `JW`로 통일
- barrier·snapshot·generation 유실 시 runtime epoch를 회전하고 전체 알려진 제어 scope를 fail-closed 재구축

## [0.2.0] - 2026-08-23

### Added

- 비회원 read 권한을 원본 미들웨어와 같은 `GuestRoleResolver`로 선검증하는 공개 게시판 목록 캐시
- 페이지 1~3, `per_page` 최대 50의 저카디널리티 hot-list 허용 정책
- PC/모바일 페이지 크기 분리 키와 상대시간·조회수 표시의 최대 60초 시계 버킷
- 게시판·글·댓글·첨부·권한·작성자 변경의 동기식 `board:all` 세대 무효화

### Changed

- 게시판 시계 버킷의 폐기 키는 10분 이내 회수하도록 정책별 보존시간을 적용
- 캐시 키 포맷을 `guest-api-v2`로 상향

## [0.1.0] - 2026-08-23

### Added

- 비회원 공개 API의 observe/active/bypass 실행 모드
- 페이지 상세 및 쇼핑몰 카테고리 공개 API의 보수적 허용목록
- 세대 벡터 검증, 분산 fill lock, 응답 저장 안전성 검사
- 캐시 HIT 직전 허용 헤더·개행·본문 크기·scope 저장물 재검증
- 카테고리 공개 응답 필터가 등록된 환경의 보수적 캐시 우회
- 운영 모드·저장소·락·복구 설정의 공개 frontend config 비노출
- 트랜잭션 아웃박스와 장애 복구 장벽
- 정상 HIT의 DB state 조회를 제거하는 전용 저장소 runtime snapshot과 커밋 전 emergency barrier
- 페이지·카테고리·상품·코어 표현 계층 변경 훅 무효화
- 모듈/플러그인 설정 초기화, 언어팩, 확장 제거·레이아웃 새로고침 훅의 site 무효화
- doctor/status/mode/purge/reconcile 운영 명령
- 적용 완료 아웃박스 및 file 저장소 만료 파일 일일 GC와 `power-cache:gc` 명령

### Compatibility

- Gnuboard7 7.0.8 이상
- 코어 공개 API를 변경하지 않는 독립 플러그인 구현
