# Loading UX G7 public API compatibility

## 결론

현재 확인 가능한 공식 최신 태그 `7.0.9`와 참고 저장소 HEAD `8aa2014fd67e`에는 `core.layout.filter_merged`와 `transition_overlay.style: skeleton`은 존재하지만, 플러그인용 공개 `window.G7Core.registerComponents()` 구현은 없습니다.

문서 `docs/extension/template-workflow.md`에는 API 사용 예시가 있으나 런타임 `G7CoreGlobals.ts`에는 해당 메서드가 없고, 공식 템플릿은 현재 `getComponentRegistry()` 또는 내부 fallback을 사용합니다. JW PowerCache는 요구사항에 따라 이 private 경로를 사용하지 않습니다.

## 태그 확인

| G7 태그 | `core.layout.filter_merged` | skeleton overlay | `G7Core.registerComponents()` 런타임 |
|---|---|---|---|
| 7.0.9 | 있음 | 있음 | 없음 |
| 7.0.8 | 있음 | 있음 | 없음 |
| 7.0.7 이하 확인 태그 | 태그별 차이 | 태그별 차이 | 없음 |

확인 명령:

```bash
git grep 'core.layout.filter_merged' 7.0.9 -- app
git grep "style: 'skeleton'" 7.0.9 -- resources/js
git grep 'G7Core.registerComponents' 7.0.9 -- resources/js
git log --all -S'G7Core.registerComponents' -- resources/js
```

## 릴리스 영향

- 플러그인 에셋은 요청된 공개 API만 호출하도록 구현하고 계약 테스트합니다.
- 공개 API가 없는 G7에서는 등록을 재시도한 뒤 조용히 중단하며 private API로 우회하지 않습니다.
- 따라서 실제 최소 G7 버전은 아직 결정할 수 없습니다. 공개 API가 포함된 공식 태그가 나온 뒤 그 태그를 최소 버전으로 확정해야 합니다.
- 이 차단점이 해소되기 전에는 Loading UX 실 G7 통합 완료, `0.4.0-beta.1` 버전 승격, 릴리스 후보 판정을 하지 않습니다.
