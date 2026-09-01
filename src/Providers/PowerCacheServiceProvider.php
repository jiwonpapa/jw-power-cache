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
use Plugins\Jw\PowerCache\LoadingUx\LoadingUxSettings;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Store\G7PowerCacheStore;

final class PowerCacheServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'jw-power_cache';

    /** @var array<int, class-string> */
    protected array $cacheServices = [
        G7PowerCacheStore::class,
    ];

    public function register(): void
    {
        parent::register();

        $root = dirname(__DIR__, 2);
        $this->mergeConfigFrom($root.'/config/power_cache.php', 'jw_power_cache');

        $this->app->scoped(PowerCacheSettings::class);
        $this->app->scoped(LoadingUxSettings::class);
        $this->app->singleton(InvalidationRepositoryInterface::class, DatabaseInvalidationRepository::class);
        $this->app->scoped(PowerCacheStoreInterface::class, G7PowerCacheStore::class);
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
}
