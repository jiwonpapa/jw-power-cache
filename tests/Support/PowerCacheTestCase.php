<?php

namespace Plugins\Jw\PowerCache\Tests\Support;

use App\Extension\Cache\PluginCacheDriver;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Plugins\Jw\PowerCache\Infrastructure\DatabaseInvalidationRepository;
use Plugins\Jw\PowerCache\Store\G7PowerCacheStore;
use Psr\Log\NullLogger;

abstract class PowerCacheTestCase extends TestCase
{
    protected Application $app;

    protected Capsule $database;

    private bool $usesExternalDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        $this->app = new Application(JW_POWER_CACHE_G7_ROOT);
        $this->app->detectEnvironment(fn (): string => 'testing');
        $this->app->instance('log', new NullLogger);
        $this->app->instance('config', new ConfigRepository([
            'app' => ['locale' => 'ko', 'fallback_locale' => 'en'],
            'database' => ['default' => 'testing'],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array', 'serialize' => true],
                ],
            ],
            'jw_power_cache' => [
                'format_version' => 2,
                'policy_version' => 'response-api-v3',
                'control_scopes' => ['site', 'page:all', 'category:tree', 'board:all'],
            ],
        ]));
        Facade::setFacadeApplication($this->app);
        $this->app->register(CacheServiceProvider::class);

        $this->database = new Capsule($this->app);
        $this->database->addConnection($this->test_database_config(), 'testing');
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->database->getDatabaseManager()->setDefaultConnection('testing');
        $transactions = new DatabaseTransactionsManager;
        $this->database->getConnection('testing')->setTransactionManager($transactions);
        $this->app->instance('db.transactions', $transactions);
        $this->app->instance('db', $this->database->getDatabaseManager());
        $this->app->instance('db.schema', $this->database->getConnection('testing')->getSchemaBuilder());

        $migration = $this->migration();
        if ($this->usesExternalDatabase) {
            $migration->down();
        }
        $migration->up();
    }

    protected function tearDown(): void
    {
        if ($this->usesExternalDatabase) {
            $this->migration()->down();
        }

        parent::tearDown();
    }

    protected function repository(): DatabaseInvalidationRepository
    {
        return new DatabaseInvalidationRepository;
    }

    protected function arrayStore(): G7PowerCacheStore
    {
        $store = new G7PowerCacheStore(new PluginCacheDriver('jw-power_cache', 'array'));
        $token = $store->markEmergencyDirty('test-bootstrap', 'test-bootstrap');
        $store->resetControlPlane(
            $this->repository()->snapshot(),
            (array) config('jw_power_cache.control_scopes', []),
            $token,
        );

        return $store;
    }

    /** @return array<string, mixed> */
    private function test_database_config(): array
    {
        if (getenv('JW_POWER_CACHE_TEST_DB_DRIVER') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ];
        }

        $database = (string) (getenv('JW_POWER_CACHE_TEST_DB_DATABASE') ?: '');
        if (getenv('JW_POWER_CACHE_TEST_DB_ALLOW_DESTRUCTIVE') !== '1'
            || preg_match('/^jw_power_cache_test(?:_[a-z0-9_]+)?$/', $database) !== 1) {
            self::fail('External DB tests require an isolated jw_power_cache_test* database and JW_POWER_CACHE_TEST_DB_ALLOW_DESTRUCTIVE=1.');
        }

        $this->usesExternalDatabase = true;

        return [
            'driver' => 'mysql',
            'host' => (string) (getenv('JW_POWER_CACHE_TEST_DB_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('JW_POWER_CACHE_TEST_DB_PORT') ?: 3306),
            'database' => $database,
            'username' => (string) (getenv('JW_POWER_CACHE_TEST_DB_USERNAME') ?: 'root'),
            'password' => (string) (getenv('JW_POWER_CACHE_TEST_DB_PASSWORD') ?: ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_08_23_000001_create_jw_power_cache_tables.php';
    }

    /** @param array<int, string> $middleware */
    protected function request(
        string $routeName = 'api.modules.sirsoft-page.pages.show',
        array $middleware = ['api', 'optional.sanctum', 'throttle:600,1'],
        string $uri = '/api/modules/sirsoft-page/pages/about',
        array $query = [],
        array $cookies = [],
        array $headers = [],
        ?string $routePattern = null,
    ): Request {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create($uri, 'GET', $query, $cookies, [], $server);
        $route = new Route(
            ['GET', 'HEAD'],
            $routePattern ?? 'api/modules/sirsoft-page/pages/{slug}',
            fn () => null,
        );
        $route->name($routeName);
        $route->middleware($middleware);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => null);
        $this->app->instance('request', $request);

        return $request;
    }
}
