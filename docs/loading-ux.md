# Loading UX

Loading UX는 PowerCache 응답 캐시와 독립된 선택 기능입니다. 비활성화하면 병합 레이아웃을 그대로 반환해 G7 기본 스피너 동작으로 즉시 복귀합니다.

## 설정

| 키 | 기본값 | 허용값 | 설명 |
|---|---:|---|---|
| `loading_ux_enabled` | `false` | boolean | 기능 ON/OFF |
| `loading_ux_scope` | `all` | `user`, `admin`, `all` | 적용 화면 |
| `loading_ux_animation` | `wave` | `wave`, `pulse`, `none` | 스켈레톤 애니메이션 |
| `loading_ux_delay_ms` | `120` | 0~1000 | 빠른 응답의 깜빡임 방지 지연 |
| `loading_ux_iteration_count` | `5` | 1~12 | 반복 placeholder 수 |

표시 지연 중 응답이 끝나면 타이머를 취소합니다. 스켈레톤이 표시된 뒤에도 최소 표시시간을 강제하지 않습니다.

## 적용 정책

- 병합 레이아웃의 유효 `transition_overlay.style`이 `spinner`일 때만 `skeleton`으로 변경합니다.
- 기존 `target`, `fallback_target`, `wait_for`, `enabled`는 보존합니다.
- `spinner` 설정은 제거하고 `JWPowerCacheSkeleton`, animation, iteration count, delay를 기록합니다.
- 공식 템플릿 내부는 [스피너 감사표](audits/loading-ux-spinner-audit.md)의 명시적 큰 콘텐츠 프로필만 교체합니다.
- 저장·삭제·업로드·결제·로그인·모달·업데이트 확인 스피너는 유지합니다.
- 타사 템플릿 내부 스피너는 보존하고 전환 오버레이만 안전하게 교체합니다.

## 스켈레톤

G7이 전달한 컴포넌트 트리의 이름과 ID를 재귀 분석해 DataGrid, 게시판 목록, 게시글 상세, 카드, 상품, 폼, 설정 화면을 구분합니다. 오버레이 target/fallback은 G7 엔진이 결정하므로 공식 헤더·메뉴를 유지하고 콘텐츠 영역만 덮습니다.

- React는 G7이 공개한 단일 `window.React` 런타임을 재사용하며 번들에 포함하지 않습니다.
- 다크 모드와 640px 이하 모바일 레이아웃을 지원합니다.
- `role=status`, `aria-busy`, `aria-live`와 상태 안내를 제공합니다.
- `prefers-reduced-motion: reduce`에서는 모든 애니메이션을 중지합니다.
- 외부 런타임 라이브러리를 추가하지 않습니다.

## 캐시 무효화

설정 저장·초기화, 플러그인 활성화·비활성화·업데이트 시 다음 범위만 무효화합니다.

1. 설치된 템플릿의 병합 레이아웃 캐시
2. 플러그인 프론트 병합 번들 캐시
3. 확장 에셋 버전 키

전역 캐시, 세션, 인증 상태, PHP-FPM은 건드리지 않습니다.

## G7 호환성 차단점

필터 훅과 skeleton 엔진은 G7 7.0.9에 있으나 공개 컴포넌트 등록 API는 없습니다. JW PowerCache는 `ComponentRegistry`, `getComponentRegistry`, `__G7_COMPONENTS__`를 사용하지 않습니다. 공식 태그에 `window.G7Core.registerComponents()`가 구현되기 전까지 실 G7 통합과 최소 버전 확정은 보류합니다. 근거는 [공개 API 호환성 기록](compatibility/loading-ux-g7-public-api.md)에 있습니다.
