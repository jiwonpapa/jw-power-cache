# G7 업데이트 배포 규칙

G7의 **플러그인 관리 → 업데이트 확인**은 GitHub `/releases/latest`의 태그에서 `v`를 제거한 버전과 설치 DB의 플러그인 버전을 PHP `version_compare(..., '>')`로 비교합니다. `main` 푸시나 태그 생성만으로는 업데이트가 표시되지 않습니다.

## 버전 기준

- 일반 배포는 `0.4.0`, 다음 수정판은 `0.4.1`처럼 증가하는 숫자 버전을 사용합니다.
- `plugin.json`, `package.json`, `package-lock.json`의 버전을 일치시킵니다. CI가 불일치를 차단합니다.
- 태그는 정확히 `v<version>`이어야 하며, 해당 커밋의 ZIP 안에도 같은 버전이 있어야 합니다. 빌드 스크립트가 검사합니다.
- `g7_version`은 지원하는 그누보드 코어 버전 조건입니다. 플러그인 업데이트 번호와 별도로 관리합니다.
- 기존 태그를 이동하거나 같은 버전의 배포 파일을 교체하지 않습니다. 수정은 새 버전으로 발행합니다.

## 배포 순서

1. 버전·CHANGELOG·`docs/releases/v<version>.md`를 갱신하고 테스트합니다.
2. 커밋을 `main`에 푸시하고 Quality의 PHP·Redis·SQLite·MySQL·MariaDB 검사를 모두 통과시킵니다.
3. 검증한 커밋에 `v<version>` 태그를 생성해 푸시합니다.
4. Release 워크플로가 테스트·ZIP·체크섬·provenance 생성 후 일반 릴리스를 Latest로 게시합니다. 게시 뒤 G7과 동일한 `/releases/latest` 조회로 태그 일치까지 검사합니다.
5. 서버의 업데이트 확인에서 `current_version < latest_version`, `update_available=true`, `is_compatible=true`를 확인합니다.
6. 설정과 복구용 백업을 보존하고 문서화된 캐시 유지보수 절차로 G7 자체 업데이트를 실행합니다. 플러그인 파일만 수동 덮어쓰지 않습니다.
7. 설치 DB·manifest 버전 일치, 설정 유지, doctor, API MISS→HIT를 확인합니다. 업데이트 후 동일 버전에 대해 `update_available=false`가 정상입니다.

하이픈이 있는 사전 릴리스는 계속 `prerelease`로 게시하며 G7의 일반 업데이트 목록에는 노출하지 않습니다. 베타 접미사 제거는 기존 정합성 보증이나 지원 범위를 확대하지 않습니다.
