<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use App\Extension\PluginManager;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Mockery;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Plugin;
use Plugins\Jw\PowerCache\Providers\PowerCacheServiceProvider;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class PowerCacheServiceProviderTest extends PowerCacheTestCase
{
    public function test_provider_uses_g7_contextual_cache_without_registering_private_stores(): void
    {
        $this->app->register(FilesystemServiceProvider::class);
        $this->app->register(CacheServiceProvider::class);
        $manager = Mockery::mock(PluginManager::class);
        $manager->shouldReceive('getPlugin')->with('jw-power_cache')->andReturn(new Plugin);
        $this->app->instance(PluginManager::class, $manager);
        (new PowerCacheServiceProvider($this->app))->register();

        self::assertNull(config('cache.stores.jw_power_cache_file'));
        self::assertNull(config('cache.stores.jw_power_cache_redis'));
        self::assertSame('array', config('cache.default'));
        self::assertTrue($this->app->bound(PowerCacheStoreInterface::class));
        self::assertTrue($this->app->bound(InvalidationRepositoryInterface::class));
        self::assertFalse($this->app->has('App\\Contracts\\Extension\\CacheInterface'));

        $store = $this->app->make(PowerCacheStoreInterface::class);
        self::assertSame('array', $store->driverName());
    }
}
