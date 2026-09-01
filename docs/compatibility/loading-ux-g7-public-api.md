# Loading UX G7 runtime compatibility

## 결론

G7 문서의 `window.G7Core.registerComponents()` 예시는 공식 7.0.9 런타임에 구현되어 있지 않습니다. PowerCache는 해당 문서를 기능 근거로 사용하지 않고 실제 태그 소스에 존재하는 다음 계약만 사용합니다.

- `core.layout.filter_merged`
- 코어 전환 전용 `#g7-skeleton-overlay`의 생성·제거 수명
- 공개 `window.G7Core.TransitionManager.subscribe()`
- 양쪽 공식 템플릿에 공통으로 등록된 `Div`와 `Span`

`ComponentRegistry`, `getComponentRegistry`, 비공개 등록 메서드, `__G7_COMPONENTS__`는 사용하지 않습니다.

## 태그 확인

| G7 태그 | 병합 필터 | 전환 오버레이 DOM | TransitionManager |
|---|---|---|---|
| 7.0.0 | 있음 | 있음 | 있음 |
| 7.0.1~7.0.8 | 있음 | 있음 | 있음 |
| 7.0.9 | 있음 | 있음 | 있음 |

확인 명령:

```bash
git grep 'core.layout.filter_merged' 7.0.9 -- app
git grep "container.id = 'g7-skeleton-overlay'" 7.0.9 -- resources/js/core/TemplateApp.ts
git grep 'G7Core.TransitionManager' 7.0.9 -- resources/js/core/template-engine/G7CoreGlobals.ts
git grep 'G7Core.registerComponents' 7.0.9 -- resources/js
```

동일 검사를 7.0.0부터 7.0.9까지 반복해 세 계약이 모두 존재함을 확인했습니다. Loading UX 자체의 소스 확인 최소 버전은 7.0.0입니다.

## 릴리스 영향

- Loading UX는 공개 등록 API 추가를 기다리지 않고 코어 수정 없이 동작합니다.
- 전환 오버레이 설정은 변경하지 않으므로 G7이 target, fallback, wait 조건과 제거 시점을 계속 관리합니다.
- 타사 템플릿의 내부 스피너는 명시적 프로필이 없으면 유지합니다.
- G7이 전환 오버레이 DOM ID를 변경하는 후속 버전에서는 원본 스피너가 남도록 fail-safe하고 호환성 프로필을 갱신해야 합니다.
- `0.4.0-beta.1` 승격 여부는 실제 G7 설치 통합과 PowerCache 전체 transaction seam 릴리스 게이트 통과 후 결정합니다.
