# Loading UX

Loading UX는 PowerCache 응답 캐시와 독립된 선택 기능입니다. 비활성화하면 레이아웃과 코어 전환 오버레이를 건드리지 않아 G7 기본 스피너 동작으로 즉시 복귀합니다.

## 설정

| 키 | 기본값 | 허용값 | 설명 |
|---|---:|---|---|
| `loading_ux_enabled` | `false` | boolean | 기능 ON/OFF |
| `loading_ux_scope` | `all` | `user`, `admin`, `all` | 적용 화면 |
| `loading_ux_animation` | `wave` | `wave`, `pulse`, `none` | 스켈레톤 애니메이션 |
| `loading_ux_delay_ms` | `120` | 0~1000 | 빠른 응답의 깜빡임 방지 지연 |
| `loading_ux_iteration_count` | `5` | 1~12 | 반복 placeholder 수 |

표시 지연 중 응답이 끝나면 타이머를 취소합니다. 표시된 뒤에도 최소 표시시간을 강제하지 않습니다.

## 실제 G7 런타임 적용 방식

G7 7.0.9 문서에는 `window.G7Core.registerComponents()` 예시가 있지만 실제 런타임 구현은 없습니다. PowerCache는 문서 예시나 private `ComponentRegistry`를 사용하지 않습니다.

1. 병합 레이아웃 필터는 공식 템플릿 내부의 명시적 큰 로딩 패턴 4개만 기본 `Div`·`Span` 트리로 교체합니다.
2. `transition_overlay` 설정은 원형 그대로 보존해 target, fallback, wait 조건과 코어 제거 시점을 G7이 계속 소유합니다.
3. 플러그인 에셋은 코어 전환 전용 `#g7-skeleton-overlay`가 생긴 경우에만 원본을 숨기고 같은 target에 형제 DOM 스켈레톤을 표시합니다.
4. 코어 오버레이가 제거되거나 공개 `G7Core.TransitionManager`가 완료를 알리면 플러그인 스켈레톤도 즉시 제거합니다.

버튼·모달·저장·결제 스피너는 전환 전용 ID가 없으므로 관찰하지 않습니다. 알 수 없는 타사 템플릿도 내부 컴포넌트는 바꾸지 않고 코어 전환 오버레이만 처리합니다.

## 스켈레톤

DOM 렌더러는 현재 경로와 전달받은 컴포넌트 이름·ID를 이용해 DataGrid, 게시판 목록, 게시글 상세, 카드, 상품, 폼, 설정 화면을 구분합니다. 코어가 선택한 target 안에서만 렌더링하므로 공식 헤더와 메뉴는 유지됩니다.

- React와 외부 런타임 라이브러리를 사용하지 않습니다.
- 다크 모드와 640px 이하 모바일 레이아웃을 지원합니다.
- `role=status`, `aria-busy`, `aria-live`와 번역된 상태 안내를 제공합니다.
- `prefers-reduced-motion: reduce`에서는 모든 애니메이션을 중지합니다.

## 캐시 무효화

설정 저장·초기화, 플러그인 활성화·비활성화·업데이트 시 다음 범위만 무효화합니다.

1. 설치된 템플릿의 병합 레이아웃 캐시
2. 플러그인 프론트 병합 번들 캐시
3. 확장 에셋 버전 키

전역 캐시, 세션, 인증 상태, PHP-FPM은 건드리지 않습니다.

## 호환성

필터 훅, 코어 전환 오버레이 ID, `TransitionManager`는 G7 7.0.0~7.0.9 태그에서 소스 확인했습니다. Loading UX 자체의 확인 가능한 최소 런타임은 7.0.0입니다. 다만 PowerCache 전체 플러그인의 최소 버전은 응답 캐시 transaction seam 계약을 별도로 따릅니다.

근거와 태그 검사 명령은 [G7 런타임 호환성 기록](compatibility/loading-ux-g7-public-api.md)에 있습니다.
