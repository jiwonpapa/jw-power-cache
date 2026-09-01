<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Jw\PowerCache\Eligibility\GuestEligibility;
use Plugins\Jw\PowerCache\Http\Middleware\GuestResponseCache;
use Plugins\Jw\PowerCache\Policy\RoutePolicy;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class GuestEligibilityTest extends PowerCacheTestCase
{
    private RoutePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new RoutePolicy(
            'page-public-show-v1',
            'api.modules.sirsoft-page.pages.show',
            ['site', 'page:all'],
            [],
            ['api', 'optional.sanctum', 'throttle:600,1'],
        );
    }

    public function test_clean_guest_request_is_eligible(): void
    {
        $registry = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $result = (new GuestEligibility($registry))->evaluate($this->request(), $this->policy);

        self::assertTrue($result->eligible);
    }

    public function test_registered_origin_response_filter_forces_bypass(): void
    {
        $filterHook = 'sirsoft-ecommerce.category.filter_public_list_result';
        $policy = new RoutePolicy(
            'ecommerce-public-category-v1',
            'api.modules.sirsoft-ecommerce.categories.index',
            ['site', 'category:tree'],
            [],
            ['api'],
            [$filterHook],
        );
        $registry = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        HookManager::addFilter($filterHook, static fn (mixed $value): mixed => $value);

        try {
            $result = (new GuestEligibility($registry))->evaluate(
                $this->request(
                    routeName: 'api.modules.sirsoft-ecommerce.categories.index',
                    middleware: ['api'],
                    uri: '/api/modules/sirsoft-ecommerce/categories',
                ),
                $policy,
            );
        } finally {
            HookManager::clearFilter($filterHook);
        }

        self::assertFalse($result->eligible);
        self::assertSame('origin_filter', $result->reason);
    }

    public function test_public_board_hot_list_requires_matching_guest_permission_and_query_bounds(): void
    {
        $policy = new RoutePolicy(
            'board-public-hot-list-v1',
            'api.modules.sirsoft-board.boards.posts.index',
            ['site', 'board:all'],
            ['page', 'per_page'],
            [
                'api',
                'optional.sanctum',
                'throttle:600,1',
                'permission:user,sirsoft-board.{slug}.posts.read',
            ],
            [],
            'sirsoft-board.{slug}.posts.read',
            true,
            60,
            3,
            50,
            600,
        );
        $registry = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $request = $this->request(
            routeName: 'api.modules.sirsoft-board.boards.posts.index',
            middleware: [
                'api',
                'optional.sanctum',
                'throttle:600,1',
                'permission:user,sirsoft-board.{slug}.posts.read',
            ],
            uri: '/api/modules/sirsoft-board/boards/freebd/posts',
            query: ['page' => '3', 'per_page' => '50'],
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

        $eligible = (new GuestEligibility($registry))->evaluate($request, $policy);
        self::assertTrue($eligible->eligible);

        $tooDeep = $this->request(
            routeName: 'api.modules.sirsoft-board.boards.posts.index',
            middleware: [
                'api',
                'optional.sanctum',
                'throttle:600,1',
                'permission:user,sirsoft-board.{slug}.posts.read',
            ],
            uri: '/api/modules/sirsoft-board/boards/freebd/posts',
            query: ['page' => '4'],
            routePattern: 'api/modules/sirsoft-board/boards/{slug}/posts',
        );
        $tooDeep->attributes->set('_guest_role_cache', $role);
        $result = (new GuestEligibility($registry))->evaluate($tooDeep, $policy);
        self::assertFalse($result->eligible);
        self::assertSame('query_range', $result->reason);
    }

    public function test_public_board_hot_list_bypasses_when_guest_read_permission_is_missing(): void
    {
        $policy = new RoutePolicy(
            'board-public-hot-list-v1',
            'api.modules.sirsoft-board.boards.posts.index',
            ['site', 'board:all'],
            ['page'],
            [
                'api',
                'optional.sanctum',
                'throttle:600,1',
                'permission:user,sirsoft-board.{slug}.posts.read',
            ],
            guestPermission: 'sirsoft-board.{slug}.posts.read',
            maxPage: 3,
        );
        $registry = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $request = $this->request(
            routeName: 'api.modules.sirsoft-board.boards.posts.index',
            middleware: $policy->allowedRouteMiddleware,
            uri: '/api/modules/sirsoft-board/boards/private/posts',
            routePattern: 'api/modules/sirsoft-board/boards/{slug}/posts',
        );
        $role = new Role(['identifier' => 'guest']);
        $role->setRelation('permissions', collect());
        $request->attributes->set('_guest_role_cache', $role);

        $result = (new GuestEligibility($registry))->evaluate($request, $policy);
        self::assertFalse($result->eligible);
        self::assertSame('guest_permission', $result->reason);
    }

    public function test_authenticated_board_list_allows_browser_session_and_checks_user_permission(): void
    {
        config()->set('session.cookie', 'g7-session');
        $policy = new RoutePolicy(
            'board-public-hot-list-v1',
            'api.modules.sirsoft-board.boards.posts.index',
            ['site', 'board:all'],
            ['page'],
            [
                'api',
                'optional.sanctum',
                'throttle:600,1',
                'permission:user,sirsoft-board.{slug}.posts.read',
            ],
            guestPermission: 'sirsoft-board.{slug}.posts.read',
            maxPage: 3,
            cacheAuthenticatedUsers: true,
            allowBrowserSession: true,
        );
        $registry = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $request = $this->request(
            routeName: 'api.modules.sirsoft-board.boards.posts.index',
            middleware: $policy->allowedRouteMiddleware,
            uri: '/api/modules/sirsoft-board/boards/freebd/posts',
            query: ['page' => '1'],
            cookies: ['g7-session' => 'session', 'XSRF-TOKEN' => 'csrf'],
            headers: [
                'Authorization' => 'Bearer test-token',
                'X-XSRF-TOKEN' => 'csrf',
            ],
            routePattern: 'api/modules/sirsoft-board/boards/{slug}/posts',
        );
        $request->setUserResolver(fn () => $this->authenticatedUser(7, true));

        $result = (new GuestEligibility($registry))->evaluate($request, $policy);

        self::assertTrue($result->eligible);
    }

    public function test_authenticated_board_list_bypasses_when_user_read_permission_is_missing(): void
    {
        $policy = new RoutePolicy(
            'board-public-hot-list-v1',
            'api.modules.sirsoft-board.boards.posts.index',
            ['site', 'board:all'],
            ['page'],
            [
                'api',
                'optional.sanctum',
                'throttle:600,1',
                'permission:user,sirsoft-board.{slug}.posts.read',
            ],
            guestPermission: 'sirsoft-board.{slug}.posts.read',
            cacheAuthenticatedUsers: true,
            allowBrowserSession: true,
        );
        $registry = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $request = $this->request(
            routeName: 'api.modules.sirsoft-board.boards.posts.index',
            middleware: $policy->allowedRouteMiddleware,
            uri: '/api/modules/sirsoft-board/boards/freebd/posts',
            headers: ['Authorization' => 'Bearer test-token'],
            routePattern: 'api/modules/sirsoft-board/boards/{slug}/posts',
        );
        $request->setUserResolver(fn () => $this->authenticatedUser(8, false));

        $result = (new GuestEligibility($registry))->evaluate($request, $policy);

        self::assertFalse($result->eligible);
        self::assertSame('user_permission', $result->reason);
    }

    public function test_public_browser_session_board_list_is_eligible_but_unknown_cookie_bypasses(): void
    {
        config()->set('session.cookie', 'g7-session');
        $policy = new RoutePolicy(
            'board-public-hot-list-v1',
            'api.modules.sirsoft-board.boards.posts.index',
            ['site', 'board:all'],
            ['page'],
            [
                'api',
                'optional.sanctum',
                'throttle:600,1',
                'permission:user,sirsoft-board.{slug}.posts.read',
            ],
            guestPermission: 'sirsoft-board.{slug}.posts.read',
            cacheAuthenticatedUsers: true,
            allowBrowserSession: true,
        );
        $registry = new class implements ExtensionMiddlewareRegistryInterface
        {
            public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
            {
                return $timing === 'after_core' ? [GuestResponseCache::class] : [];
            }
        };
        $makeRequest = function (array $cookies) use ($policy): Request {
            $request = $this->request(
                routeName: 'api.modules.sirsoft-board.boards.posts.index',
                middleware: $policy->allowedRouteMiddleware,
                uri: '/api/modules/sirsoft-board/boards/freebd/posts',
                query: ['page' => '1'],
                cookies: $cookies,
                headers: [
                    'Authorization' => 'Bearer stale-browser-token',
                    'X-XSRF-TOKEN' => 'csrf',
                ],
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

        $allowed = (new GuestEligibility($registry))->evaluate(
            $makeRequest(['g7-session' => 'session', 'XSRF-TOKEN' => 'csrf']),
            $policy,
        );
        $unknown = (new GuestEligibility($registry))->evaluate(
            $makeRequest(['g7-session' => 'session', 'analytics' => 'variant']),
            $policy,
        );

        self::assertTrue($allowed->eligible);
        self::assertFalse($unknown->eligible);
        self::assertSame('cookie', $unknown->reason);
    }

    public function test_authenticated_request_still_bypasses_routes_without_user_isolation(): void
    {
        $request = $this->request();
        $request->setUserResolver(fn () => $this->authenticatedUser(9, true));

        $result = (new GuestEligibility)->evaluate($request, $this->policy);

        self::assertFalse($result->eligible);
        self::assertSame('authenticated_user', $result->reason);
    }

    #[DataProvider('unsafeRequestProvider')]
    public function test_personalized_or_unknown_inputs_bypass(string $kind, string $expected): void
    {
        $request = match ($kind) {
            'authorization' => $this->request(headers: ['Authorization' => 'Bearer expired']),
            'cookie' => $this->request(cookies: ['laravel_session' => 'x']),
            'query' => $this->request(query: ['preview' => '1']),
            'middleware' => $this->request(middleware: ['api', 'permission:user,anything']),
            'extension_middleware' => $this->request(),
        };

        $eligibility = $kind === 'extension_middleware'
            ? new GuestEligibility(new class implements ExtensionMiddlewareRegistryInterface
            {
                public function resolveForRoute(string $routeName, string $path, string $group, string $timing): array
                {
                    return $timing === 'after_core'
                        ? [GuestResponseCache::class, 'Plugins\\Vendor\\Personalizer']
                        : [];
                }
            })
            : new GuestEligibility;
        $result = $eligibility->evaluate($request, $this->policy);

        self::assertFalse($result->eligible);
        self::assertSame($expected, $result->reason);
    }

    public static function unsafeRequestProvider(): array
    {
        return [
            ['authorization', 'sensitive_header'],
            ['cookie', 'cookie'],
            ['query', 'unknown_query'],
            ['middleware', 'unknown_route_middleware'],
            ['extension_middleware', 'unknown_extension_middleware'],
        ];
    }

    private function authenticatedUser(int $id, bool $hasPermission): object
    {
        return new class($id, $hasPermission)
        {
            public function __construct(
                private readonly int $id,
                private readonly bool $allowed,
            ) {}

            public function getAuthIdentifier(): int
            {
                return $this->id;
            }

            public function hasPermission(string $permission, PermissionType $type): bool
            {
                return $this->allowed
                    && $permission === 'sirsoft-board.freebd.posts.read'
                    && $type === PermissionType::User;
            }
        };
    }
}
