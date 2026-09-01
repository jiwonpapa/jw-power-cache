# Loading UX spinner audit

감사 기준은 G7 참고 저장소 `8aa2014fd67e`의 공식 번들 템플릿입니다. 문자열 일괄 치환이 아니라 각 노드의 레이아웃 이름, 컴포넌트, 조건, 부모 구조를 확인했습니다.

## 결과

| 범위 | 원형 로딩 노드 | 분류 | Loading UX 처리 |
|---|---:|---|---|
| `sirsoft-basic` | 44 | 큰 콘텐츠 4, 작업 39, 장식 1 | 큰 콘텐츠 4건만 명시적 프로필로 교체 |
| `sirsoft-admin_basic` | 33 | 작업 32, 장식 1 | 내부 노드는 모두 유지 |
| 합계 | 77 | 큰 콘텐츠 4, 작업 71, 장식 2 | 4건 교체, 73건 유지 |

## 교체하는 공식 큰 콘텐츠 패턴

| 템플릿 레이아웃 | 조건 | 노드와 부모 구조 | 프로필 |
|---|---|---|---|
| `board/index` | `!posts?.data?.board && !_global.hasError` | `Div.animate-spin.h-12.w-12.border-4` + `Div.flex-col.items-center.py-16` | board |
| `users/show` | `profile.loading` | `Div.animate-spin.h-8.w-8.border-b-2` + `Div.justify-center.items-center.py-20` | detail |
| `users/posts` | `userPosts.loading && !userPosts.data` | 위와 같은 큰 목록 로딩 구조 | board |
| `shop/reorder` | `_local.status === 'pending'` | `Icon(loader-2).animate-spin.text-4xl` + `Div.flex-col.justify-center.py-16` | product |

프로필은 네 조건을 모두 만족해야 적용됩니다. 같은 CSS 클래스만 가진 다른 노드는 교체하지 않습니다.

## 유지하는 스피너

- 저장, 삭제, 업로드, 결제, 로그인, 비밀번호 확인, 신고, 업데이트 확인 버튼의 작업 진행 표시
- 모달 내부 제출·삭제·설치·제거·미리보기 진행 표시
- 유지보수 화면의 회전 기어 2건(상태 장식이며 콘텐츠 로딩 오버레이가 아님)
- 알 수 없는 타사 템플릿의 내부 스피너

## 전환 오버레이

- 사용자 공식 템플릿은 `_user_base`의 `main_content_area` 스피너를 상속합니다. 마이페이지 7개 화면은 `mypage_tab_content`로 범위를 좁히고, `mypage/inquiries`는 이미 skeleton입니다.
- 관리자 공식 템플릿은 `_admin_base`의 `right_content_area` 스피너를 상속합니다. 17개 화면이 `wait_for` 또는 세부 target을 병합합니다.
- Loading UX가 켜지면 병합 후 유효 style이 `spinner`인 오버레이만 `skeleton`으로 바꿉니다. `target`, `fallback_target`, `wait_for`, `enabled`는 그대로 보존합니다.
- 이미 skeleton, opaque, blur, fade인 오버레이는 변경하지 않습니다.
