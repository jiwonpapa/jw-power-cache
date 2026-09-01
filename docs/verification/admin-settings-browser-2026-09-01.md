# Admin settings browser verification — 2026-09-01

## Verdict

JW PowerCache의 관리자 설정은 격리된 G7 7.0.10 웹 환경에서 실제 브라우저로 렌더링, 저장, 재로드, 런타임 반영을 통과했다. 읽기 전용 관리자 계정은 설정 조회만 가능했고 저장은 HTTP 403으로 차단됐다. 검증 후 실행 모드는 `bypass`로 복원했으며 임시 사용자, 토큰, 역할은 삭제했다.

## Environment

- Core: Gnuboard 7 transaction-seam commit `7d628dc4e57153a6217372a8a4bf8ea2904c680f`
- Web: Apache 2.4.67 to PHP-FPM 8.5.3
- Database/cache: MySQL 8.4.11 and Redis 7.4.11 (`noeviction`)
- Admin template: `sirsoft-admin_basic` 1.0.7
- Plugin candidate: `0.3.0-alpha.3`

## Browser checks

| Check | Evidence | Result |
|---|---|:---:|
| Settings page rendering | mode/store, three cache route toggles, recovery/metrics/debug toggles, and five numeric limits rendered | PASS |
| Change tracking | changing mode from `bypass` to `observe` enabled the Save button | PASS |
| Save feedback | browser displayed `설정이 저장되었습니다.` and disabled Save after refetch | PASS |
| Reload persistence | a full browser reload retained `observe` | PASS |
| Runtime reflection | `power-cache:status --json` reported `mode=observe`, `driver=redis`, no warnings or errors | PASS |
| Safe restoration | browser saved `bypass`; status reported `mode=bypass`, pending outbox zero, no errors | PASS |

The runtime epoch changed from `2bd0709d-d729-4eda-b83b-c589a65be858` after the observe save to `5b65cec0-7bd7-416c-a896-68f71ae48a57` after restoring bypass. This confirms that the settings mutation reached the plugin invalidation path rather than changing only browser state.

## Permission checks

- Unauthenticated GET and PUT requests to the core plugin settings API returned HTTP 401.
- A temporary administrator holding only `core.plugins.read` received HTTP 200 for GET and HTTP 403 for PUT.
- The browser save was performed by an administrator with `core.plugins.update` and returned the visible success state.
- The layout itself declares `core.plugins.update`; the core PUT route independently enforces `permission:admin,core.plugins.update`.

## Contract defect found and fixed

The browser rendered `cache_public_board_lists`, but that key was absent from the layout's validation `schema`. The key was added, and the unit contract now compares the complete key set across the plugin schema, defaults, frontend non-exposure schema, admin form fields, and admin layout schema.

## Cleanup and boundary

Both temporary audit users, personal access tokens, and the temporary read-only role were deleted. No production system was accessed, no tag or GitHub release was created, and this result remains local verification against the versioned G7 transaction-seam candidate.
