<?php

namespace Plugins\Jw\PowerCache\Diagnostics;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use Illuminate\Support\Facades\Route;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Http\Middleware\GuestResponseCache;
use Plugins\Jw\PowerCache\Policy\RoutePolicyRegistry;
use Plugins\Jw\PowerCache\Runtime\CoreCompatibility;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Runtime\RecoveryBarrier;
use Throwable;

final class PowerCacheInspector
{
    public function __construct(
        private readonly PowerCacheSettings $settings,
        private readonly InvalidationRepositoryInterface $repository,
        private readonly PowerCacheStoreInterface $store,
        private readonly RoutePolicyRegistry $policies,
        private readonly ExtensionMiddlewareRegistryInterface $extensionMiddleware,
        private readonly RecoveryBarrier $barrier,
        private readonly CoreCompatibility $compatibility,
    ) {}

    /** @return array<string, mixed> */
    public function inspect(bool $probeStore = true): array
    {
        $warnings = [];
        $errors = [];
        $tablesReady = false;
        $snapshot = null;
        $pending = null;
        $driver = $this->store->driverName();
        $storeProbe = ['ok' => null, 'driver' => $driver];
        $emergencyDirty = null;
        $barrierPresent = false;

        if (! $this->compatibility->supportsTransactionalActions()) {
            $warnings[] = 'G7 표준 동기 훅으로 무효화합니다. 동일 트랜잭션 훅이 있는 코어보다 장애 구간 보장이 제한됩니다.';
        }

        try {
            $tablesReady = $this->repository->tablesReady();
            if ($tablesReady) {
                $snapshot = $this->repository->snapshot();
                $pending = $this->repository->pendingCount();
            }
        } catch (Throwable $e) {
            $errors[] = 'state: '.$e->getMessage();
        }

        try {
            if ($probeStore) {
                $storeProbe = $this->store->probe();
                $this->barrier->inspect((array) config('jw_power_cache.control_scopes', []));
            }
            $control = $this->store->controlBarrier();
            $barrierPresent = $control !== null;
            $emergencyDirty = $control?->dirty;
        } catch (Throwable $e) {
            $storeProbe = ['ok' => false, 'driver' => $driver, 'error' => $e->getMessage()];
        }

        if ($driver === 'file') {
            $warnings[] = 'G7 관리자가 file 캐시를 선택했습니다. 여러 웹 노드에서는 G7 관리자에서 공유 캐시 저장소를 선택하십시오.';
        }

        $routes = [];
        foreach ($this->policies->routeNames() as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $middleware = $route?->gatherMiddleware() ?? [];
            $middlewareMatches = $route === null
                ? null
                : array_values($middleware) === $this->policies->expectedMiddleware($routeName);
            $extensionBefore = $route === null
                ? []
                : $this->extensionMiddleware->resolveForRoute($routeName, ltrim($route->uri(), '/'), 'api', 'before_core');
            $extensionAfter = $route === null
                ? []
                : $this->extensionMiddleware->resolveForRoute($routeName, ltrim($route->uri(), '/'), 'api', 'after_core');
            $extensionMiddlewareMatches = $route === null
                ? null
                : $extensionBefore === [] && $extensionAfter === [GuestResponseCache::class];
            $routes[$routeName] = [
                'available' => $route !== null,
                'middleware' => $middleware,
                'middleware_matches' => $middlewareMatches,
                'extension_middleware_before' => $extensionBefore,
                'extension_middleware_after' => $extensionAfter,
                'extension_middleware_matches' => $extensionMiddlewareMatches,
            ];

            if ($route === null) {
                $warnings[] = "선택 라우트가 현재 설치에 없습니다: {$routeName}";
            } elseif (! $middlewareMatches) {
                $message = "라우트 미들웨어 계약이 바뀌었습니다: {$routeName}";
                if ($probeStore || $this->settings->mode() === 'active') {
                    $errors[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
            if ($route !== null && ! $extensionMiddlewareMatches) {
                $message = "다른 확장 미들웨어가 겹치거나 PowerCache 등록 계약이 바뀌었습니다: {$routeName}";
                if ($probeStore || $this->settings->mode() === 'active') {
                    $errors[] = $message;
                } else {
                    $warnings[] = $message;
                }
            }
        }

        if ($probeStore) {
            if (! $tablesReady) {
                $errors[] = '플러그인 테이블이 없습니다.';
            }
            if (($storeProbe['ok'] ?? false) !== true) {
                $errors[] = '캐시 저장소 probe가 실패했습니다.';
            }
            if ($emergencyDirty === true) {
                $errors[] = '비상 dirty 장벽이 활성화되어 있습니다.';
            }
            if (! $barrierPresent) {
                $errors[] = '제어 장벽 키가 없거나 손상되었습니다.';
            }
            if ($snapshot?->isDirty()) {
                $errors[] = '미적용 아웃박스가 있어 HIT가 차단됩니다.';
            }
        }

        if ($this->settings->mode() === 'active') {
            if (! $tablesReady) {
                $errors[] = 'active 모드인데 플러그인 테이블이 없습니다.';
            }
            if ($probeStore && ($storeProbe['ok'] ?? false) !== true) {
                $errors[] = 'active 모드인데 캐시 저장소 probe가 실패했습니다.';
            }
            if (isset($storeProbe['error'])) {
                $errors[] = 'active 모드인데 캐시 저장소 상태를 읽지 못했습니다.';
            }
            if ($emergencyDirty === true) {
                $errors[] = '비상 dirty 장벽이 활성화되어 있습니다.';
            }
            if (! $barrierPresent) {
                $errors[] = 'active 모드인데 제어 장벽 키가 없거나 손상되었습니다.';
            }
            if ($snapshot?->isDirty()) {
                $errors[] = '미적용 아웃박스가 있어 HIT가 차단됩니다.';
            }
        }

        $warnings = array_values(array_unique($warnings));
        $errors = array_values(array_unique($errors));

        return [
            'ok' => $errors === [],
            'mode' => $this->settings->mode(),
            'driver' => $driver,
            'tables_ready' => $tablesReady,
            'site_id' => $snapshot?->siteId,
            'runtime_epoch' => $snapshot?->runtimeEpoch,
            'dirty_event_id' => $snapshot?->dirtyEventId,
            'pending_outbox' => $pending,
            'barrier_present' => $barrierPresent,
            'emergency_dirty' => $emergencyDirty,
            'store' => $storeProbe,
            'transactional_actions' => $this->compatibility->supportsTransactionalActions(),
            'routes' => $routes,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
}
