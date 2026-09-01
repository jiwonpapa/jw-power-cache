<?php

namespace Plugins\Jw\PowerCache\Tests\Feature;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Plugins\Jw\PowerCache\Eligibility\GuestEligibility;
use Plugins\Jw\PowerCache\Http\Middleware\GuestResponseCache;
use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\InvalidationCoordinator;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Keys\CanonicalRequestKey;
use Plugins\Jw\PowerCache\Policy\ResponsePolicy;
use Plugins\Jw\PowerCache\Policy\RoutePolicyRegistry;
use Plugins\Jw\PowerCache\Runtime\CoreCompatibility;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Runtime\RecoveryBarrier;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class GuestResponseCacheTest extends PowerCacheTestCase
{
    public function test_active_mode_bypasses_when_core_transactional_hooks_are_unsupported(): void
    {
        $settings = new PowerCacheSettings([
            'mode' => 'active',
            'store_driver' => 'array',
            'cache_public_pages' => true,
            'automatic_recovery' => true,
            'metrics_enabled' => false,
            'debug_headers' => true,
        ]);
        $repository = $this->repository();
        $store = $this->arrayStore();
        $applier = new InvalidationApplier($repository, $store);
        $reconciler = new OutboxReconciler($repository, $store, $applier);
        $middleware = new GuestResponseCache(
            $settings,
            new RoutePolicyRegistry($settings),
            new GuestEligibility(new class implements ExtensionMiddlewareRegistryInterface
            {
                public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
                {
                    return $timing === 'after_core' ? [GuestResponseCache::class] : [];
                }
            }),
            new CanonicalRequestKey,
            new ResponsePolicy,
            $store,
            new RecoveryBarrier($repository, $store, $reconciler, $settings),
            new CoreCompatibility(false),
        );

        $originCalls = 0;
        $response = $middleware->handle($this->request(), function () use (&$originCalls): JsonResponse {
            $originCalls++;

            return new JsonResponse(['origin_call' => $originCalls]);
        });

        self::assertSame(1, $originCalls);
        self::assertSame('BYPASS; reason=core_transactional_hooks', $response->headers->get('X-JW-Power-Cache'));
    }

    public function test_public_board_list_hits_and_board_generation_invalidates_it(): void
    {
        $settings = new PowerCacheSettings([
            'mode' => 'active',
            'store_driver' => 'array',
            'cache_public_board_lists' => true,
            'automatic_recovery' => true,
            'metrics_enabled' => false,
            'debug_headers' => true,
        ]);
        $repository = $this->repository();
        $store = $this->arrayStore();
        $applier = new InvalidationApplier($repository, $store);
        $reconciler = new OutboxReconciler($repository, $store, $applier);
        $middleware = new GuestResponseCache(
            $settings,
            new RoutePolicyRegistry($settings),
            new GuestEligibility(new class implements ExtensionMiddlewareRegistryInterface
            {
                public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
                {
                    return $timing === 'after_core' ? [GuestResponseCache::class] : [];
                }
            }),
            new CanonicalRequestKey,
            new ResponsePolicy,
            $store,
            new RecoveryBarrier($repository, $store, $reconciler, $settings),
            new CoreCompatibility(true),
        );
        $makeRequest = function () {
            $request = $this->request(
                routeName: 'api.modules.sirsoft-board.boards.posts.index',
                middleware: [
                    'api',
                    'optional.sanctum',
                    'throttle:600,1',
                    'permission:user,sirsoft-board.{slug}.posts.read',
                ],
                uri: '/api/modules/sirsoft-board/boards/freebd/posts',
                query: ['page' => '1'],
                headers: ['User-Agent' => 'Mozilla/5.0 Macintosh'],
                routePattern: 'api/modules/sirsoft-board/boards/{slug}/posts',
            );
            $role = new Role(['identifier' => 'guest']);
            $role->setRelation('permissions', collect([
                new Permission([
                    'identifier' => 'sirsoft-board.freebd.posts.read',
                    'type' => PermissionType::User,
                ]),
            ]));
            $request->attributes->set('_guest_role_cache', $role);

            return $request;
        };
        $originCalls = 0;
        $origin = function () use (&$originCalls): JsonResponse {
            $originCalls++;

            return new JsonResponse(['origin_call' => $originCalls]);
        };

        $first = $middleware->handle($makeRequest(), $origin);
        $second = $middleware->handle($makeRequest(), $origin);
        self::assertSame(1, $originCalls);
        self::assertStringStartsWith('MISS-STORED', (string) $first->headers->get('X-JW-Power-Cache'));
        self::assertStringStartsWith('HIT', (string) $second->headers->get('X-JW-Power-Cache'));

        $coordinator = new InvalidationCoordinator($repository, $store, $applier);
        self::assertNotNull($coordinator->invalidate(['board:all'], 'test-board-update'));
        $third = $middleware->handle($makeRequest(), $origin);
        self::assertSame(2, $originCalls);
        self::assertStringStartsWith('MISS-STORED', (string) $third->headers->get('X-JW-Power-Cache'));
    }

    public function test_miss_hit_and_generation_invalidation_flow(): void
    {
        $settings = new PowerCacheSettings([
            'mode' => 'active',
            'store_driver' => 'array',
            'cache_public_pages' => true,
            'cache_public_categories' => true,
            'automatic_recovery' => true,
            'metrics_enabled' => false,
            'debug_headers' => true,
        ]);
        $repository = $this->repository();
        $store = $this->arrayStore();
        $applier = new InvalidationApplier($repository, $store);
        $reconciler = new OutboxReconciler($repository, $store, $applier);
        $barrier = new RecoveryBarrier($repository, $store, $reconciler, $settings);
        $extensionMiddleware = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $middleware = new GuestResponseCache(
            $settings,
            new RoutePolicyRegistry($settings),
            new GuestEligibility($extensionMiddleware),
            new CanonicalRequestKey,
            new ResponsePolicy,
            $store,
            $barrier,
            new CoreCompatibility(true),
        );

        $originCalls = 0;
        $origin = function () use (&$originCalls): JsonResponse {
            $originCalls++;

            return new JsonResponse(['origin_call' => $originCalls]);
        };

        $first = $middleware->handle($this->request(), $origin);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $middleware->handle($this->request(), $origin);
        $hitQueries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame(1, $originCalls);
        self::assertStringStartsWith('MISS-STORED', (string) $first->headers->get('X-JW-Power-Cache'));
        self::assertStringStartsWith('HIT', (string) $second->headers->get('X-JW-Power-Cache'));
        self::assertSame($first->getContent(), $second->getContent());
        self::assertSame([], $hitQueries, '정상 HIT 경로는 DB를 조회하면 안 됩니다.');

        $coordinator = new InvalidationCoordinator($repository, $store, $applier);
        $eventId = $coordinator->invalidate(['page:all'], 'test-page-update');
        self::assertNotNull($eventId);
        self::assertSame(0, $repository->snapshot()->dirtyEventId);

        $third = $middleware->handle($this->request(), $origin);
        self::assertSame(2, $originCalls);
        self::assertStringStartsWith('MISS-STORED', (string) $third->headers->get('X-JW-Power-Cache'));
    }

    public function test_malformed_stored_entries_are_never_served(): void
    {
        $settings = new PowerCacheSettings([
            'mode' => 'active',
            'store_driver' => 'array',
            'cache_public_pages' => true,
            'cache_public_categories' => true,
            'automatic_recovery' => true,
            'metrics_enabled' => false,
            'debug_headers' => true,
            'max_response_kb' => 16,
        ]);
        $repository = $this->repository();
        $store = $this->arrayStore();
        $applier = new InvalidationApplier($repository, $store);
        $reconciler = new OutboxReconciler($repository, $store, $applier);
        $barrier = new RecoveryBarrier($repository, $store, $reconciler, $settings);
        $extensionMiddleware = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $policies = new RoutePolicyRegistry($settings);
        $keys = new CanonicalRequestKey;
        $middleware = new GuestResponseCache(
            $settings,
            $policies,
            new GuestEligibility($extensionMiddleware),
            $keys,
            new ResponsePolicy,
            $store,
            $barrier,
            new CoreCompatibility(true),
        );
        $request = $this->request();
        $snapshot = $repository->snapshot();
        $policy = $policies->resolve($request);
        self::assertNotNull($policy);
        $requestKey = $keys->build(
            $request,
            $policy,
            $snapshot,
            app()->getLocale(),
            date_default_timezone_get(),
        );
        $generations = $store->generations($policy->scopes);
        $baseEntry = [
            'version' => 1,
            'status' => 200,
            'headers' => ['Content-Type' => ['application/json']],
            'body' => '{"cached":true}',
            'scopes' => $policy->scopes,
            'generations' => $generations,
            'stored_at' => time(),
        ];
        $malformedEntries = [
            array_replace($baseEntry, ['headers' => ['X-Unsafe' => ['1']]]),
            array_replace($baseEntry, ['headers' => ['Content-Type' => ["application/json\r\nX-Injected: 1"]]]),
            array_replace($baseEntry, ['body' => str_repeat('x', (16 * 1024) + 1)]),
            array_replace($baseEntry, ['scopes' => ['site', 1]]),
        ];

        $originCalls = 0;
        foreach ($malformedEntries as $entry) {
            $store->putResponse($requestKey, $entry, 3600);
            $response = $middleware->handle($request, function () use (&$originCalls): JsonResponse {
                $originCalls++;

                return new JsonResponse(['origin_call' => $originCalls]);
            });

            self::assertStringStartsWith('MISS-STORED', (string) $response->headers->get('X-JW-Power-Cache'));
            self::assertStringNotContainsString('"cached":true', (string) $response->getContent());
        }

        self::assertSame(count($malformedEntries), $originCalls);
    }
}
