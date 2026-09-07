# 기여 안내

## 개발 환경 준비

JW PowerCache는 별도의 그누보드7 소스 저장소를 연결해 테스트합니다.

```bash
git clone https://github.com/gnuboard/g7.git /path/to/g7
composer install --working-dir=/path/to/g7 --no-scripts
G7_ROOT=/path/to/g7 tool/test.sh
```

실제 Redis 통합 테스트를 실행하려면 `JW_POWER_CACHE_TEST_REDIS_URL`을 설정합니다. 현재 CI는 PHP 8.2/8.5, 공식 G7 7.0.9의 고정 커밋, Redis 7.4, MySQL 8.4, MariaDB 11.4를 검사합니다.

변경 제안(PR)을 제출하기 전에 다음을 실행합니다.

```bash
composer validate --strict --no-check-publish
/path/to/g7/vendor/bin/pint --test src tests database plugin.php
G7_ROOT=/path/to/g7 JW_POWER_CACHE_TEST_REDIS_URL=redis://127.0.0.1:6379/15 tool/test.sh
```

## 변경 원칙

- 안전을 확인할 수 없으면 캐시 응답을 차단하는 정합성 원칙을 유지합니다. 저장소나 Redis 오류 시 원본으로 우회할 수 있지만, 오래되거나 다른 사용자의 개인화 데이터를 반환해서는 안 됩니다.
- 정합성 오류를 수정할 때마다 회귀 테스트를 추가합니다.
- 위협과 호환성을 분석하지 않고 경로·쿼리·미들웨어·헤더·쿠키 허용목록을 넓히지 않습니다.
- 성능 개선을 주장할 때는 측정 조건과 변경 전후 원본 결과를 함께 문서화합니다.
- 커밋은 한 주제에 집중하며, 인증정보·운영 DB 덤프·생성된 릴리스 압축파일은 포함하지 않습니다.
- 문서와 사용자 안내, 이슈·PR 양식, 릴리스 설명은 한국어로 작성합니다. 코드·명령어·설정 키·API 이름은 원문을 유지합니다.
- 측정 원본과 표준 라이선스 원문은 변조하지 않고 한국어 설명을 별도로 제공합니다.
- 업데이트를 배포할 때는 [G7 업데이트 배포 규칙](docs/releases/README.md)을 따릅니다.
