<?php

namespace Plugins\Jw\PowerCache\Tests\Feature;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\InvalidationCoordinator;
use Plugins\Jw\PowerCache\Listeners\CoreInvalidationListener;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class TransactionalHookIntegrationTest extends PowerCacheTestCase
{
    private const CALLER_TABLE = 'jw_power_cache_test_content';

    protected function setUp(): void
    {
        parent::setUp();

        if (! method_exists(HookManager::class, 'doTransactionalAction')) {
            self::markTestSkipped('Official G7 7.0.9 uses standard synchronous hooks.');
        }

        $this->app->instance('events', new Dispatcher($this->app));
        HookManager::resetAll();
        HookListenerRegistrar::clear();

        Schema::create(self::CALLER_TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::CALLER_TABLE);
        HookManager::resetAll();
        HookListenerRegistrar::clear();

        parent::tearDown();
    }

    public function test_core_mutation_hook_commits_caller_write_and_invalidation_together(): void
    {
        [$repository, $store] = $this->registerCoreListener();

        DB::transaction(function () {
            DB::table(self::CALLER_TABLE)->insert(['title' => 'committed']);
            HookManager::doTransactionalAction('core.user.after_update');
        });

        self::assertSame(1, DB::table(self::CALLER_TABLE)->count());
        self::assertSame(0, $repository->pendingCount());
        self::assertGreaterThan(0, $store->generations(['board:all'])['board:all']);
        self::assertFalse($store->controlBarrier()?->dirty);
    }

    public function test_caller_rollback_removes_outbox_and_clears_its_barrier(): void
    {
        [$repository, $store] = $this->registerCoreListener();

        DB::beginTransaction();
        DB::table(self::CALLER_TABLE)->insert(['title' => 'rolled-back']);
        HookManager::doTransactionalAction('core.user.after_update');
        DB::rollBack();

        self::assertSame(0, DB::table(self::CALLER_TABLE)->count());
        self::assertSame(0, $repository->pendingCount());
        self::assertSame(['board:all' => 0], $store->generations(['board:all']));
        self::assertFalse($store->controlBarrier()?->dirty);
    }

    /** @return array{InvalidationRepositoryInterface, PowerCacheStoreInterface} */
    private function registerCoreListener(): array
    {
        $repository = $this->repository();
        $store = $this->arrayStore();
        $coordinator = new InvalidationCoordinator(
            $repository,
            $store,
            new InvalidationApplier($repository, $store),
        );

        $this->app->instance(InvalidationRepositoryInterface::class, $repository);
        $this->app->instance(PowerCacheStoreInterface::class, $store);
        $this->app->instance(InvalidationCoordinator::class, $coordinator);
        HookListenerRegistrar::register(CoreInvalidationListener::class, 'jw-power_cache');

        return [$repository, $store];
    }
}
