<?php

namespace Plugins\Jw\PowerCache\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Jw\PowerCache\Console\Commands\DoctorCommand;
use Plugins\Jw\PowerCache\Console\Commands\GcCommand;
use Plugins\Jw\PowerCache\Console\Commands\ModeCommand;
use Plugins\Jw\PowerCache\Console\Commands\PurgeCommand;
use Plugins\Jw\PowerCache\Console\Commands\ReconcileCommand;
use Plugins\Jw\PowerCache\Console\Commands\RestoreFinalizeCommand;
use Plugins\Jw\PowerCache\Console\Commands\StatusCommand;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Infrastructure\DatabaseInvalidationRepository;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Store\LaravelPowerCacheStore;

final class PowerCacheServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'jw-power_cache';

    public function register(): void
    {
        parent::register();

        $root = dirname(__DIR__, 2);
        $this->mergeConfigFrom($root.'/config/power_cache.php', 'jw_power_cache');
        $this->registerDedicatedStores();

        $this->app->scoped(PowerCacheSettings::class);
        $this->app->singleton(InvalidationRepositoryInterface::class, DatabaseInvalidationRepository::class);
        $this->app->scoped(PowerCacheStoreInterface::class, function ($app): PowerCacheStoreInterface {
            $settings = $app->make(PowerCacheSettings::class);
            $driver = $settings->storeDriver();

            $storeName = (string) config("jw_power_cache.stores.{$driver}");

            return new LaravelPowerCacheStore(
                $app['cache']->store($storeName),
                $driver,
                $driver === 'file' ? (string) config('jw_power_cache.file.path') : null,
                $driver === 'file' ? (string) config('jw_power_cache.file.gc_safe_root') : null,
            );
        });
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                DoctorCommand::class,
                GcCommand::class,
                ModeCommand::class,
                StatusCommand::class,
                PurgeCommand::class,
                ReconcileCommand::class,
                RestoreFinalizeCommand::class,
            ]);
        }
    }

    private function registerDedicatedStores(): void
    {
        config([
            'cache.stores.jw_power_cache_file' => [
                'driver' => 'file',
                'path' => config('jw_power_cache.file.path'),
                'lock_path' => config('jw_power_cache.file.path').'/locks',
            ],
            'cache.stores.jw_power_cache_redis' => [
                'driver' => 'redis',
                'connection' => config('jw_power_cache.redis.connection'),
                'lock_connection' => config('jw_power_cache.redis.connection'),
                'prefix' => config('jw_power_cache.redis.prefix'),
            ],
            'cache.stores.jw_power_cache_array' => [
                'driver' => 'array',
                'serialize' => true,
            ],
            'database.redis.jw_power_cache' => [
                'url' => config('jw_power_cache.redis.url'),
                'host' => config('jw_power_cache.redis.host'),
                'username' => config('jw_power_cache.redis.username'),
                'password' => config('jw_power_cache.redis.password'),
                'port' => config('jw_power_cache.redis.port'),
                'database' => config('jw_power_cache.redis.database'),
                'max_retries' => 1,
                'backoff_algorithm' => 'decorrelated_jitter',
                'backoff_base' => 50,
                'backoff_cap' => 250,
            ],
        ]);
    }
}
