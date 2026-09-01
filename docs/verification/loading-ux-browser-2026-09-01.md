# Loading UX browser verification — 2026-09-01

## Evidence boundary

This is a real-browser verification of the plugin-owned `JWPowerCacheSkeleton` bundle in a local G7-shaped harness. It is not evidence of end-to-end registration in G7 because tagged G7 releases through 7.0.9 do not implement the required public `window.G7Core.registerComponents()` API. No production system was accessed or changed.

## Result

| Scenario | Result | Evidence |
|---|---|---|
| Fast cache HIT, 60ms | PASS | At 100ms the skeleton count was 0 and actual content was visible. Unit fake-timer coverage also proves the 120ms reveal timer is cancelled on unmount. |
| Slow API | PASS | Skeleton becomes visible after 120ms with `aria-busy=true`; header, menu, and action spinner remain visible. |
| User / admin / board / shop / mypage | PASS | Profiles resolved to cards / settings / board / product / detail. |
| Desktop | PASS | Content-only board skeleton, five rows, header/menu preserved. |
| Mobile 390×844 | PASS | Product cards collapse to one 325px column inside a 349px skeleton root. |
| Dark mode | PASS | Computed skeleton background `rgb(17, 24, 39)`, settings profile visible. |
| Reduced motion | PASS | Emulated `prefers-reduced-motion: reduce`; wave pseudo-element computed `animation-name: none`. |
| Accessibility | PASS | `role=status`, `aria-busy=true`, `aria-live=polite`, localized status text. |
| Action spinner regression | PASS | Separate “저장 중” action spinner remains visible in all five screen checks. |

The browser result supports a default `loading_ux_delay_ms=120`: a 60ms HIT never paints the skeleton, while a slow response reveals it after the threshold. No minimum display time is added.

## Screenshots

- [Desktop board slow response](evidence/loading-ux-board-desktop-slow.png)
- [Desktop fast HIT complete](evidence/loading-ux-board-fast-hit.png)
- [Dark admin settings](evidence/loading-ux-admin-dark.png)
- [Mobile shop slow response](evidence/loading-ux-shop-mobile-slow.png)

The local harness is under `tests/Browser/` and is excluded from release archives with the rest of the test suite.
