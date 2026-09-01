<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Providers\PowerCacheServiceProvider;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class PowerCacheServiceProviderTest extends PowerCacheTestCase
{
    public function test_provider_registers_only_plugin_specific_store_and_repository_bindings(): void
    {
        $this->app->register(FilesystemServiceProvider::class);
        $this->app->register(CacheServiceProvider::class);
        (new PowerCacheServiceProvider($this->app))->register();

        self::assertSame('file', config('cache.stores.jw_power_cache_file.driver'));
        self::assertSame('redis', config('cache.stores.jw_power_cache_redis.driver'));
        self::assertSame('array', config('cache.stores.jw_power_cache_array.driver'));
        self::assertTrue($this->app->bound(PowerCacheStoreInterface::class));
        self::assertTrue($this->app->bound(InvalidationRepositoryInterface::class));
        self::assertFalse($this->app->has('App\\Contracts\\Extension\\CacheInterface'));

        $store = $this->app->make(PowerCacheStoreInterface::class);
        self::assertSame('file', $store->driverName());
    }
}
