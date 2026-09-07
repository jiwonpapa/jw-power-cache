# 로딩 화면 개선 기능 브라우저 검증 — 2026-09-01

## 근거 범위

G7 7.0.9의 `#g7-skeleton-overlay` 생명주기와 `TransitionManager` 신호를 재현한 로컬 시험 환경에서, 플러그인 소유 DOM 스켈레톤 렌더러를 실제 브라우저로 검증했다. 운영 배포나 로그인된 G7 설치 환경 전체의 종단간 검증은 아니다. 운영 시스템에 접근하거나 변경하지 않았다.

## 결과

| 시나리오 | 결과 | 근거 |
|---|---|---|
| 빠른 캐시 HIT, 60ms | 통과 | 100ms 시점에 스켈레톤·플러그인 오버레이 수가 0이고 실제 콘텐츠 표시 |
| 느린 API | 통과 | 코어 전환 컨테이너를 관찰해 120ms 후 `aria-busy=true`로 스켈레톤 표시, G7이 제거할 때까지 원래 코어 컨테이너 보존 |
| 사용자·관리자·게시판·쇼핑몰·마이페이지 | 통과 | 각각 cards·settings·board·product·detail 프로필 선택 |
| 데스크톱 | 통과 | 콘텐츠 영역에만 게시판 스켈레톤 5행 표시, 헤더·메뉴 보존 |
| 모바일 390×844 | 통과 | 상품 카드가 한 열로 배치되고 가로 넘침 없음 |
| 다크 모드 | 통과 | 스켈레톤 배경 계산값 `rgb(17, 24, 39)`, 설정 프로필 표시 |
| 동작 줄이기 | 통과 | `prefers-reduced-motion: reduce` 모의 적용, 물결 가상 요소의 계산값 `animation-name: none` |
| 접근성 | 통과 | `role=status`, `aria-busy=true`, `aria-live=polite`, 번역된 상태 안내 |
| 작업 스피너 회귀 검사 | 통과 | 화면 5종 모두 별도 “저장 중” 작업 스피너 유지 |

이 결과는 기본 `loading_ux_delay_ms=120`을 뒷받침한다. 60ms HIT에는 스켈레톤을 표시하지 않고, 느린 응답은 기준 시간을 넘기면 표시한다. 최소 표시시간은 추가하지 않는다.

## 화면 기록

- [데스크톱 게시판 느린 응답](evidence/loading-ux-board-desktop-slow.png)
- [데스크톱 빠른 HIT 완료](evidence/loading-ux-board-fast-hit.png)
- [다크 모드 관리자 설정](evidence/loading-ux-admin-dark.png)
- [모바일 쇼핑몰 느린 응답](evidence/loading-ux-shop-mobile-slow.png)

로컬 시험 환경은 `tests/Browser/`에 있으며 다른 테스트와 함께 배포 압축파일에서 제외된다. 컴포넌트 레지스트리 등록은 모의 구현하거나 사용하지 않는다.
