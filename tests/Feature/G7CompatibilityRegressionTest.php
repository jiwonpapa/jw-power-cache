<?php

namespace Plugins\Jw\PowerCache\Tests\Feature;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Enums\PermissionType;
use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Eligibility\GuestEligibility;
use Plugins\Jw\PowerCache\Http\Middleware\GuestResponseCache;
use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\InvalidationCoordinator;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Keys\CanonicalRequestKey;
use Plugins\Jw\PowerCache\Listeners\ContentInvalidationListener;
use Plugins\Jw\PowerCache\Listeners\CoreInvalidationListener;
use Plugins\Jw\PowerCache\Policy\ResponsePolicy;
use Plugins\Jw\PowerCache\Policy\RoutePolicyRegistry;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Runtime\RecoveryBarrier;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

final class G7CompatibilityRegressionTest extends PowerCacheTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('events', new Dispatcher($this->app));
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        config(['session.cookie' => 'g7-session']);
    }

    protected function tearDown(): void
    {
        HookManager::resetAll();
        HookListenerRegistrar::clear();
        parent::tearDown();
    }

    public function test_official_analytics_cookie_values_share_the_public_board_cache(): void
    {
        config(['analytics.cookies' => ['visitor' => 'g7_vid', 'opt_out' => 'g7_analytics_optout']]);
        $middleware = $this->runtime();
        $originCalls = 0;
        $origin = function () use (&$originCalls): JsonResponse {
            $originCalls++;

            return new JsonResponse(['origin_call' => $originCalls]);
        };
        $first = $middleware->handle($this->boardRequest([
            'g7-session' => 'session-a', 'XSRF-TOKEN' => 'csrf-a', 'g7_vid' => str_repeat('a', 32),
        ]), $origin);
        $second = $middleware->handle($this->boardRequest([
            'g7-session' => 'session-b', 'XSRF-TOKEN' => 'csrf-b', 'g7_vid' => str_repeat('b', 32),
            'g7_analytics_optout' => '1',
        ]), $origin);

        self::assertStringStartsWith('MISS-STORED;', $first->headers->get('X-JW-Power-Cache'));
        self::assertStringStartsWith('HIT;', $second->headers->get('X-JW-Power-Cache'));
        self::assertSame($first->getContent(), $second->getContent());
        self::assertSame(1, $originCalls);

        $unknown = $middleware->handle($this->boardRequest(['personalized_variant' => 'vip']), $origin);
        self::assertSame('BYPASS; reason=cookie', $unknown->headers->get('X-JW-Power-Cache'));
        self::assertSame(2, $originCalls);
    }

    public function test_only_configured_analytics_cookie_names_are_allowed(): void
    {
        $middleware = $this->runtime();
        $origin = fn (): JsonResponse => new JsonResponse(['public' => true]);
        $withoutConfiguration = $middleware->handle($this->boardRequest(['g7_vid' => 'visitor']), $origin);
        self::assertSame('BYPASS; reason=cookie', $withoutConfiguration->headers->get('X-JW-Power-Cache'));

        config(['analytics.cookies' => ['visitor' => 'site_visitor', 'opt_out' => 'site_optout']]);
        $configured = $middleware->handle($this->boardRequest(['site_visitor' => 'visitor', 'site_optout' => '1']), $origin);
        self::assertStringStartsWith('MISS-STORED;', $configured->headers->get('X-JW-Power-Cache'));
        $oldName = $middleware->handle($this->boardRequest(['g7_vid' => 'visitor']), $origin);
        self::assertSame('BYPASS; reason=cookie', $oldName->headers->get('X-JW-Power-Cache'));

        $page = $middleware->handle($this->request(cookies: ['site_visitor' => 'visitor']), $origin);
        self::assertSame('BYPASS; reason=cookie', $page->headers->get('X-JW-Power-Cache'));
    }

    #[DataProvider('reactionActions')]
    public function test_reactions_invalidate_a_warm_board_list_without_waiting_for_the_clock_bucket(string $action): void
    {
        $middleware = $this->runtime();
        $count = 0;
        $originCalls = 0;
        $origin = function () use (&$count, &$originCalls): JsonResponse {
            $originCalls++;

            return new JsonResponse(['reaction_count' => $count]);
        };
        $first = $middleware->handle($this->boardRequest(), $origin);
        $hit = $middleware->handle($this->boardRequest(), $origin);
        self::assertStringStartsWith('HIT;', $hit->headers->get('X-JW-Power-Cache'));
        self::assertSame($first->getContent(), $hit->getContent());

        $count = 1;
        HookManager::doAction('sirsoft-board.reaction.after_react', 9, null, 1, $action, 'post', 42);
        $updated = $middleware->handle($this->boardRequest(), $origin);
        self::assertStringStartsWith('MISS-STORED;', $updated->headers->get('X-JW-Power-Cache'));
        self::assertSame(1, json_decode($updated->getContent(), true)['reaction_count']);
        self::assertSame(2, $originCalls);
    }

    public static function reactionActions(): array
    {
        return [['add'], ['change'], ['remove']];
    }

    public function test_bulk_role_changes_refresh_cached_abilities_and_read_permission_is_still_checked(): void
    {
        $middleware = $this->runtime();
        $readAllowed = true;
        $writeAllowed = true;
        $user = new class($readAllowed)
        {
            public function __construct(private bool &$allowed) {}

            public function getAuthIdentifier(): int
            {
                return 9;
            }

            public function hasPermission(string $permission, PermissionType $type): bool
            {
                return $this->allowed;
            }
        };
        $request = function () use ($user): Request {
            $request = $this->boardRequest();
            $request->setUserResolver(fn () => $user);

            return $request;
        };
        $origin = function () use (&$writeAllowed): JsonResponse {
            return new JsonResponse(['abilities' => ['create' => $writeAllowed]]);
        };
        $middleware->handle($request(), $origin);
        self::assertStringStartsWith('HIT;', $middleware->handle($request(), $origin)->headers->get('X-JW-Power-Cache'));

        $writeAllowed = false;
        HookManager::doAction('core.user.after_bulk_update_roles', [9], [2], 'remove', [['user_id' => 9, 'removed' => [2], 'added' => []]]);
        $updated = $middleware->handle($request(), $origin);
        self::assertStringStartsWith('MISS-STORED;', $updated->headers->get('X-JW-Power-Cache'));
        self::assertFalse(json_decode($updated->getContent(), true)['abilities']['create']);

        $readAllowed = false;
        $denied = $middleware->handle($request(), fn (): JsonResponse => new JsonResponse(['denied' => true], 403));
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('BYPASS; reason=user_permission', $denied->headers->get('X-JW-Power-Cache'));
    }

    private function runtime(): GuestResponseCache
    {
        $repository = $this->repository();
        $store = $this->arrayStore();
        $applier = new InvalidationApplier($repository, $store);
        $coordinator = new InvalidationCoordinator($repository, $store, $applier);
        $this->app->instance(InvalidationRepositoryInterface::class, $repository);
        $this->app->instance(PowerCacheStoreInterface::class, $store);
        $this->app->instance(InvalidationCoordinator::class, $coordinator);
        HookListenerRegistrar::register(ContentInvalidationListener::class, 'jw-power_cache');
        HookListenerRegistrar::register(CoreInvalidationListener::class, 'jw-power_cache');

        $settings = new PowerCacheSettings(['mode' => 'active', 'metrics_enabled' => false, 'debug_headers' => true]);

        return new GuestResponseCache(
            $settings, new RoutePolicyRegistry($settings),
            new GuestEligibility(new class implements ExtensionMiddlewareRegistryInterface
            {
                public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
                {
                    return $timing === 'after_core' ? [GuestResponseCache::class] : [];
                }
            }),
            new CanonicalRequestKey, new ResponsePolicy, $store,
            new RecoveryBarrier($repository, $store, new OutboxReconciler($repository, $store, $applier), $settings),
        );
    }

    private function boardRequest(array $cookies = []): Request
    {
        $request = $this->request(
            routeName: 'api.modules.sirsoft-board.boards.posts.index',
            middleware: ['api', 'optional.sanctum', 'throttle:600,1', 'permission:user,sirsoft-board.{slug}.posts.read'],
            uri: '/api/modules/sirsoft-board/boards/freebd/posts',
            query: ['page' => 1],
            cookies: $cookies,
            routePattern: 'api/modules/sirsoft-board/boards/{slug}/posts',
        );
        $role = new Role(['identifier' => 'guest', 'is_active' => true]);
        $role->setRelation('permissions', collect([new Permission([
            'identifier' => 'sirsoft-board.freebd.posts.read', 'type' => PermissionType::User,
        ])]));
        $request->attributes->set('_guest_role_cache', $role);

        return $request;
    }
}
