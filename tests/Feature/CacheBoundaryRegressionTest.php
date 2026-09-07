<?php

namespace Plugins\Jw\PowerCache\Tests\Feature;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Enums\PermissionType;
use App\Helpers\TimezoneHelper;
use App\Models\Permission;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Services\PostService;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Eligibility\GuestEligibility;
use Plugins\Jw\PowerCache\Http\Middleware\GuestResponseCache;
use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Keys\CanonicalRequestKey;
use Plugins\Jw\PowerCache\Policy\ResponsePolicy;
use Plugins\Jw\PowerCache\Policy\RoutePolicyRegistry;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Runtime\RecoveryBarrier;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;

final class CacheBoundaryRegressionTest extends PowerCacheTestCase
{
    public function test_get_json_body_cannot_fill_or_read_the_plain_board_list_cache(): void
    {
        [$middleware] = $this->runtime();
        $body = ['search' => 'body-only-filter', 'per_page' => 1];
        $request = $this->boardRequest($body);
        self::assertSame([], $request->query->all());
        self::assertSame($body, $request->all());

        // Exercise the same input parser that G7's PostController uses.
        require_once JW_POWER_CACHE_G7_ROOT.'/modules/_bundled/sirsoft-board/src/Services/PostService.php';
        $service = (new ReflectionClass(PostService::class))->newInstanceWithoutConstructor();
        $originCalls = 0;
        $origin = function (Request $request) use ($service, &$originCalls): JsonResponse {
            $originCalls++;
            $params = $service->buildListParams($request->all(), ['context' => 'user']);

            return new JsonResponse(['search' => $params['filters']['search'], 'per_page' => $params['perPage']]);
        };

        $withBody = $middleware->handle($request, $origin);
        self::assertSame('BYPASS; reason=request_body', $withBody->headers->get('X-JW-Power-Cache'));
        $plain = $middleware->handle($this->boardRequest(), $origin);
        self::assertSame(['search' => null, 'per_page' => 20], json_decode($plain->getContent(), true));
        $hit = $middleware->handle($this->boardRequest(), $origin);
        self::assertStringStartsWith('HIT;', $hit->headers->get('X-JW-Power-Cache'));
        self::assertSame($plain->getContent(), $hit->getContent());

        $bodyAfterFill = $middleware->handle($this->boardRequest($body), $origin);
        self::assertSame('BYPASS; reason=request_body', $bodyAfterFill->headers->get('X-JW-Power-Cache'));
        self::assertSame($body, json_decode($bodyAfterFill->getContent(), true));
        self::assertSame(3, $originCalls);
    }

    #[DataProvider('timezoneRoutes')]
    public function test_effective_user_timezone_partitions_cached_dates(string $route): void
    {
        [$middleware] = $this->runtime();
        $request = fn (): Request => $route === 'board' ? $this->boardRequest() : $this->request();
        $origin = fn (): JsonResponse => new JsonResponse([
            'date' => TimezoneHelper::toUserDateTimeString(Carbon::parse('2026-09-07 00:00:00', 'UTC')),
        ]);

        $this->app->instance('user_timezone', 'Asia/Seoul');
        $seoul = $middleware->handle($request(), $origin);
        $this->app->instance('user_timezone', 'America/New_York');
        $newYork = $middleware->handle($request(), $origin);
        $hit = $middleware->handle($request(), $origin);

        self::assertSame('2026-09-07 09:00:00', json_decode($seoul->getContent(), true)['date']);
        self::assertSame('2026-09-06 20:00:00', json_decode($newYork->getContent(), true)['date']);
        self::assertStringStartsWith('MISS-STORED;', $newYork->headers->get('X-JW-Power-Cache'));
        self::assertStringStartsWith('HIT;', $hit->headers->get('X-JW-Power-Cache'));
        self::assertSame($newYork->getContent(), $hit->getContent());
    }

    public static function timezoneRoutes(): array
    {
        return [['page'], ['board']];
    }

    #[DataProvider('recoveryStates')]
    public function test_scheduled_recovery_reopens_pending_and_previously_stranded_barriers(bool $alreadyApplied): void
    {
        [, $repository, $store, $reconciler, $barrier] = $this->runtime();
        $event = $this->pendingEvent($repository, $store);
        if ($alreadyApplied) {
            $store->advanceGenerations(['page:all'], $event);
            $repository->markApplied($event);
            $repository->clearDirtyWhenRecovered();
        }

        $result = $reconciler->reconcile(100);

        self::assertSame($alreadyApplied ? 0 : 1, $result['applied']);
        self::assertSame(0, $result['remaining']);
        self::assertNull($result['error']);
        self::assertSame(0, $repository->snapshot()->dirtyEventId);
        self::assertFalse($store->controlBarrier()->dirty);
        self::assertTrue($barrier->inspect(['site', 'page:all'])->ready);
    }

    public static function recoveryStates(): array
    {
        return ['pending' => [false], 'previously applied' => [true]];
    }

    public function test_scheduled_recovery_does_not_clear_a_control_plane_reset(): void
    {
        [, $repository, $store, $reconciler] = $this->runtime();
        $token = $store->markEmergencyDirty('snapshot_missing', 'recovery:in-progress');
        $event = DB::transaction(function () use ($repository): int {
            $id = $repository->append(['page:all'], 'pending-before-reset');
            $repository->markDirty($id);

            return $id;
        });

        $result = $reconciler->reconcile(100);

        self::assertSame(1, $result['applied']);
        self::assertSame(0, $result['remaining']);
        self::assertSame($event, $store->generations(['page:all'])['page:all']);
        self::assertSame($token, $store->controlBarrier()->token);
        self::assertTrue($store->controlBarrier()->dirty);
    }

    public function test_scheduled_recovery_preserves_a_newer_writers_barrier(): void
    {
        $backing = $this->arrayStore();
        $repository = $this->repository();
        $oldEvent = $this->pendingEvent($repository, $backing);
        $newEvent = null;
        $store = $this->forwardingStore($backing, [
            'clearEmergencyDirty' => function (?string $token = null) use ($backing, $repository, &$newEvent): bool {
                $newEvent = $this->pendingEvent($repository, $backing);

                return $backing->clearEmergencyDirty($token);
            },
        ]);
        [, , , $reconciler] = $this->runtime($store);

        $result = $reconciler->reconcile(100);

        self::assertSame(1, $result['applied']);
        self::assertSame(1, $result['remaining']);
        self::assertGreaterThan($oldEvent, $newEvent);
        self::assertSame('event:'.$newEvent, $backing->controlBarrier()->token);
        self::assertTrue($backing->controlBarrier()->dirty);
    }

    #[DataProvider('hitPaths')]
    public function test_all_hit_paths_reject_a_concurrent_pending_invalidation(string $path): void
    {
        $backing = $this->arrayStore();
        $repository = $this->repository();
        $reading = false;
        $reads = 0;
        $injected = false;
        $store = $this->forwardingStore($backing, [
            'getResponse' => function (string $key) use ($backing, $repository, $path, &$reading, &$reads, &$injected): ?array {
                $entry = $backing->getResponse($key);
                if ($reading) {
                    $reads++;
                    if ($path !== 'direct' && $reads === 1) {
                        return null;
                    }
                    if ($entry !== null && ! $injected) {
                        $injected = true;
                        $this->pendingEvent($repository, $backing);
                    }
                }

                return $entry;
            },
            'acquireLock' => function (string $name, int $lease) use ($backing, $path, &$reading) {
                return $reading && $path === 'wait' && str_starts_with($name, 'fill:')
                    ? null
                    : $backing->acquireLock($name, $lease);
            },
        ]);
        [$middleware] = $this->runtime($store);
        $middleware->handle($this->request(), fn (): JsonResponse => new JsonResponse(['revision' => 'old']));
        $reading = true;
        $response = $middleware->handle($this->request(), fn (): JsonResponse => new JsonResponse(['revision' => 'new']));

        self::assertTrue($injected);
        self::assertSame(1, $repository->pendingCount());
        self::assertSame('new', json_decode($response->getContent(), true)['revision']);
        self::assertStringStartsWith('MISS;', $response->headers->get('X-JW-Power-Cache'));
    }

    public static function hitPaths(): array
    {
        return [['direct'], ['lock'], ['wait']];
    }

    public function test_epoch_reset_during_entry_read_rejects_old_generation_zero(): void
    {
        $backing = $this->arrayStore();
        $repository = $this->repository();
        $reset = false;
        $store = $this->forwardingStore($backing, [
            'getResponse' => function (string $key) use ($backing, $repository, &$reset): ?array {
                $entry = $backing->getResponse($key);
                if ($reset && $entry !== null) {
                    $reset = false;
                    $token = $backing->markEmergencyDirty('audit-reset', 'recovery:reset');
                    $repository->rotateRuntimeEpoch();
                    $backing->resetControlPlane($repository->snapshot(), config('jw_power_cache.control_scopes'), $token);
                }

                return $entry;
            },
        ]);
        [$middleware] = $this->runtime($store);
        $middleware->handle($this->request(), fn (): JsonResponse => new JsonResponse(['revision' => 'old']));
        $reset = true;
        $response = $middleware->handle($this->request(), fn (): JsonResponse => new JsonResponse(['revision' => 'new']));

        self::assertSame('new', json_decode($response->getContent(), true)['revision']);
        self::assertSame('MISS; reason=barrier_changed', $response->headers->get('X-JW-Power-Cache'));
    }

    public function test_barrier_change_during_generation_read_blocks_the_final_check(): void
    {
        $backing = $this->arrayStore();
        $snapshot = $this->repository()->snapshot();
        $scopes = ['site', 'page:all'];
        $generations = $backing->generations($scopes);
        $store = $this->forwardingStore($backing, [
            'generations' => function (array $scopes) use ($backing): array {
                $values = $backing->generations($scopes);
                // The same numeric generations can survive an epoch reset.
                $token = $backing->markEmergencyDirty('reset', 'recovery:race');
                $backing->resetControlPlane($this->repository()->snapshot(), $scopes, $token);

                return $values;
            },
        ]);
        [, , , , $barrier] = $this->runtime($store);

        self::assertFalse($barrier->stillReady($snapshot, $scopes, $generations));
    }

    public function test_policy_upgrade_does_not_reuse_old_response_keys(): void
    {
        [$middleware] = $this->runtime();
        config(['jw_power_cache.policy_version' => 'response-api-v3']);
        $middleware->handle($this->request(), fn (): JsonResponse => new JsonResponse(['policy' => 'old']));
        config(['jw_power_cache.policy_version' => 'response-api-v4']);
        $response = $middleware->handle($this->request(), fn (): JsonResponse => new JsonResponse(['policy' => 'new']));

        self::assertSame('new', json_decode($response->getContent(), true)['policy']);
        self::assertStringStartsWith('MISS-STORED;', $response->headers->get('X-JW-Power-Cache'));
    }

    private function pendingEvent(InvalidationRepositoryInterface $repository, PowerCacheStoreInterface $store): int
    {
        return DB::transaction(function () use ($repository, $store): int {
            $event = $repository->append(['page:all'], 'interrupted-apply');
            $repository->markDirty($event);
            $store->markEmergencyDirty('outbox_pending:'.$event, 'event:'.$event, $event);

            return $event;
        });
    }

    private function forwardingStore(PowerCacheStoreInterface $backing, array $overrides): PowerCacheStoreInterface
    {
        $store = $this->createMock(PowerCacheStoreInterface::class);
        foreach (get_class_methods(PowerCacheStoreInterface::class) as $method) {
            $store->method($method)->willReturnCallback($overrides[$method] ?? fn (...$args) => $backing->$method(...$args));
        }

        return $store;
    }

    private function runtime(?PowerCacheStoreInterface $store = null): array
    {
        $settings = new PowerCacheSettings([
            'mode' => 'active', 'metrics_enabled' => false,
            'debug_headers' => true, 'automatic_recovery' => true, 'lock_wait_ms' => 100,
        ]);
        $store ??= $this->arrayStore();
        $repository = $this->repository();
        $reconciler = new OutboxReconciler($repository, $store, new InvalidationApplier($repository, $store));
        $barrier = new RecoveryBarrier($repository, $store, $reconciler, $settings);
        $middleware = new GuestResponseCache(
            $settings, new RoutePolicyRegistry($settings),
            new GuestEligibility(new class implements ExtensionMiddlewareRegistryInterface
            {
                public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
                {
                    return $timing === 'after_core' ? [GuestResponseCache::class] : [];
                }
            }),
            new CanonicalRequestKey, new ResponsePolicy, $store, $barrier,
        );

        return [$middleware, $repository, $store, $reconciler, $barrier];
    }

    private function boardRequest(?array $body = null): Request
    {
        $template = $this->request(
            routeName: 'api.modules.sirsoft-board.boards.posts.index',
            middleware: ['api', 'optional.sanctum', 'throttle:600,1', 'permission:user,sirsoft-board.{slug}.posts.read'],
            uri: '/api/modules/sirsoft-board/boards/audit/posts',
            routePattern: 'api/modules/sirsoft-board/boards/{slug}/posts',
        );
        $request = $template;
        if ($body !== null) {
            $request = Request::create(
                $template->getUri(), 'GET', [], [], [],
                ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR),
            );
            $request->setRouteResolver(fn () => $template->route());
            $request->setUserResolver(fn () => null);
            $this->app->instance('request', $request);
        }
        $role = new Role(['identifier' => 'guest']);
        $role->setRelation('permissions', collect([new Permission([
            'identifier' => 'sirsoft-board.audit.posts.read', 'type' => PermissionType::User,
        ])]));
        $request->attributes->set('_guest_role_cache', $role);

        return $request;
    }
}
