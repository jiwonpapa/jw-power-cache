<?php

namespace Plugins\Jw\PowerCache\Eligibility;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Enums\PermissionType;
use App\Extension\HookManager;
use App\Support\GuestRoleResolver;
use Closure;
use Illuminate\Http\Request;
use Plugins\Jw\PowerCache\Http\Middleware\GuestResponseCache;
use Plugins\Jw\PowerCache\Policy\RoutePolicy;
use Throwable;

final class GuestEligibility
{
    public function __construct(
        private readonly ?ExtensionMiddlewareRegistryInterface $extensionMiddleware = null,
    ) {}

    private const ALWAYS_SENSITIVE_HEADERS = [
        'Proxy-Authorization',
        'X-G7-Guest-Order-Token',
        'X-Guest-Order-Token',
        'X-Cart-Key',
        'X-Preview-Token',
        'X-Signature',
    ];

    public function evaluate(Request $request, RoutePolicy $policy): EligibilityResult
    {
        if (! in_array($request->getRealMethod(), ['GET', 'HEAD'], true)) {
            return EligibilityResult::bypass('method');
        }

        $user = $request->user();

        foreach (self::ALWAYS_SENSITIVE_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return EligibilityResult::bypass('sensitive_header');
            }
        }

        if ($request->headers->has('Authorization')) {
            $bearerToken = $request->bearerToken();
            if (! $policy->cacheAuthenticatedUsers
                || ! is_string($bearerToken)
                || $bearerToken === '') {
                return EligibilityResult::bypass('sensitive_header');
            }
        }

        foreach (['X-XSRF-TOKEN', 'X-CSRF-TOKEN'] as $header) {
            if ($request->headers->has($header) && ! $policy->allowBrowserSession) {
                return EligibilityResult::bypass('sensitive_header');
            }
        }

        if ($user !== null && ! $policy->cacheAuthenticatedUsers) {
            return EligibilityResult::bypass('authenticated_user');
        }

        if ($request->cookies->count() > 0
            && (! $policy->allowBrowserSession
                || ($user === null && ! $this->onlyBrowserSessionCookies($request)))) {
            return EligibilityResult::bypass('cookie');
        }

        $queryKeys = array_keys($request->query->all());
        $unknownQuery = array_diff($queryKeys, $policy->allowedQueryKeys);
        if ($unknownQuery !== []) {
            return EligibilityResult::bypass('unknown_query');
        }

        if (! $this->queryRangeIsSafe($request, $policy)) {
            return EligibilityResult::bypass('query_range');
        }

        $registeredFilters = HookManager::getFilters();
        foreach ($policy->originFilterHooks as $filterHook) {
            if (($registeredFilters[$filterHook] ?? []) !== []) {
                return EligibilityResult::bypass('origin_filter');
            }
        }

        $middlewareResult = $this->routeMiddlewareIsSafe($request, $policy);
        if ($middlewareResult !== null) {
            return EligibilityResult::bypass($middlewareResult);
        }

        $extensionMiddlewareResult = $this->extensionMiddlewareIsSafe($request);
        if ($extensionMiddlewareResult !== null) {
            return EligibilityResult::bypass($extensionMiddlewareResult);
        }

        if ($policy->guestPermission !== null) {
            $permission = $this->resolvePermission($request, $policy->guestPermission);
            if ($permission === null) {
                return EligibilityResult::bypass($user === null ? 'guest_permission' : 'user_permission');
            }

            if ($user !== null) {
                if (! $this->userHasPermission($user, $permission)) {
                    return EligibilityResult::bypass('user_permission');
                }
            } elseif (! GuestRoleResolver::hasPermission($permission, PermissionType::User)) {
                return EligibilityResult::bypass('guest_permission');
            }
        }

        return EligibilityResult::allow();
    }

    private function routeMiddlewareIsSafe(Request $request, RoutePolicy $policy): ?string
    {
        $route = $request->route();
        if ($route === null) {
            return 'route_missing';
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if ($middleware instanceof Closure || ! is_string($middleware)) {
                return 'unknown_route_middleware';
            }

            if (! in_array($middleware, $policy->allowedRouteMiddleware, true)) {
                return 'unknown_route_middleware';
            }
        }

        return null;
    }

    private function extensionMiddlewareIsSafe(Request $request): ?string
    {
        if ($this->extensionMiddleware === null) {
            return 'extension_registry_unavailable';
        }

        $routeName = $request->route()?->getName() ?? '';
        $path = $request->path();
        $before = $this->extensionMiddleware->resolveForRoute($routeName, $path, 'api', 'before_core');
        $after = $this->extensionMiddleware->resolveForRoute($routeName, $path, 'api', 'after_core');

        if ($before !== [] || $after !== [GuestResponseCache::class]) {
            return 'unknown_extension_middleware';
        }

        return null;
    }

    private function queryRangeIsSafe(Request $request, RoutePolicy $policy): bool
    {
        if ($policy->maxPage !== null
            && ! $this->boundedPositiveInteger($request->query('page'), $policy->maxPage)) {
            return false;
        }

        return $policy->maxPerPage === null
            || $this->boundedPositiveInteger($request->query('per_page'), $policy->maxPerPage);
    }

    private function boundedPositiveInteger(mixed $value, int $maximum): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return false;
        }

        $integer = (int) $value;

        return $integer >= 1 && $integer <= $maximum;
    }

    private function resolvePermission(Request $request, string $template): ?string
    {
        $missing = false;
        $resolved = preg_replace_callback('/\{(\w+)\}/', function (array $matches) use ($request, &$missing): string {
            $value = $request->route($matches[1]);
            if (! is_scalar($value) || (string) $value === '') {
                $missing = true;

                return '';
            }

            return (string) $value;
        }, $template);

        return $missing || ! is_string($resolved) || $resolved === '' ? null : $resolved;
    }

    private function onlyBrowserSessionCookies(Request $request): bool
    {
        $allowed = ['XSRF-TOKEN'];
        $sessionCookie = config('session.cookie', 'laravel_session');
        if (is_string($sessionCookie) && $sessionCookie !== '') {
            $allowed[] = $sessionCookie;
        }

        return array_diff(array_keys($request->cookies->all()), $allowed) === [];
    }

    private function userHasPermission(mixed $user, string $permission): bool
    {
        if (! is_object($user) || ! method_exists($user, 'hasPermission')) {
            return false;
        }

        try {
            return $user->hasPermission($permission, PermissionType::User) === true;
        } catch (Throwable) {
            return false;
        }
    }
}
