<?php

namespace Plugins\Jw\PowerCache\Diagnostics;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use Illuminate\Support\Facades\Route;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Http\Middleware\GuestResponseCache;
use Plugins\Jw\PowerCache\Policy\RoutePolicyRegistry;
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
    ) {}

    /** @return array<string, mixed> */
    public function inspect(bool $probeStore = true): array
    {
        $warnings = [];
        $errors = [];
        $tablesReady = false;
        $snapshot = null;
        $pending = null;
        $storeProbe = ['ok' => null, 'driver' => $this->settings->storeDriver()];
        $emergencyDirty = null;
        $barrierPresent = false;

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
            $storeProbe = ['ok' => false, 'driver' => $this->settings->storeDriver(), 'error' => $e->getMessage()];
        }

        if ($this->settings->storeDriver() === 'file'
            && ! (bool) config('jw_power_cache.file.single_node_ack', false)) {
            $warnings[] = 'file 드라이버 active 모드는 JW_POWER_CACHE_FILE_SINGLE_NODE=true 확인이 필요합니다.';
        }

        if ($this->settings->storeDriver() === 'redis') {
            $powerDb = (string) config('jw_power_cache.redis.database');
            $defaultDb = (string) config('database.redis.default.database');
            $cacheDb = (string) config('database.redis.cache.database');
            if ($powerDb === $defaultDb || $powerDb === $cacheDb) {
                $warnings[] = 'PowerCache Redis DB가 기본/캐시 연결과 겹칩니다. 전용 DB를 사용하십시오.';
            }

            $redisProbe = is_array($storeProbe['redis'] ?? null) ? $storeProbe['redis'] : [];
            $evictionPolicy = (string) ($redisProbe['maxmemory_policy'] ?? '');
            if (str_starts_with($evictionPolicy, 'allkeys-')) {
                $warnings[] = "Redis {$evictionPolicy} 정책은 제어 키도 제거할 수 있습니다. volatile-* 또는 전용 noeviction 인스턴스를 권장합니다.";
            }
            if ((int) ($redisProbe['evicted_keys'] ?? 0) > 0) {
                $warnings[] = 'Redis evicted_keys가 0보다 큽니다. 메모리와 MISS/epoch 회전 추이를 확인하십시오.';
            }
            if (isset($redisProbe['diagnostics_error'])) {
                $warnings[] = 'Redis eviction 설정과 통계를 읽지 못했습니다. 운영 모니터링에서 별도로 확인하십시오.';
            }
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
            if ($this->settings->storeDriver() === 'file'
                && ! (bool) config('jw_power_cache.file.single_node_ack', false)) {
                $errors[] = 'file 단일 노드 확인 전에는 active HIT가 차단됩니다.';
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
            if ($this->settings->storeDriver() === 'file'
                && ! (bool) config('jw_power_cache.file.single_node_ack', false)) {
                $errors[] = 'file 단일 노드 확인 전에는 active HIT가 차단됩니다.';
            }
        }

        $warnings = array_values(array_unique($warnings));
        $errors = array_values(array_unique($errors));

        return [
            'ok' => $errors === [],
            'mode' => $this->settings->mode(),
            'driver' => $this->settings->storeDriver(),
            'tables_ready' => $tablesReady,
            'site_id' => $snapshot?->siteId,
            'runtime_epoch' => $snapshot?->runtimeEpoch,
            'dirty_event_id' => $snapshot?->dirtyEventId,
            'pending_outbox' => $pending,
            'barrier_present' => $barrierPresent,
            'emergency_dirty' => $emergencyDirty,
            'store' => $storeProbe,
            'routes' => $routes,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }
}
