<?php

declare(strict_types=1);

namespace Tests\integration\AI;

use FireflyIII\Models\Account;
use FireflyIII\Support\Cache\UserScopedCache;
use Illuminate\Support\Facades\Cache;
use Tests\integration\TestCase;

/**
 * @group integration-test
 * @group ai
 *
 * @internal
 */
final class DebtSimulationCacheInvalidationTest extends TestCase
{
    public function testFlushesCacheOnAccountUpdated(): void
    {
        Cache::flush();
        $account = new Account(['user_id' => 1, 'user_group_id' => 1]);

        UserScopedCache::remember('1', '1', 'debt-sim-test', fn() => 'cached', 3600);
        $key = 'u:1:g:1:debt-sim-test';
        self::assertTrue(Cache::has($key));

        event('eloquent.updated: ' . Account::class, $account);

        self::assertFalse(Cache::has($key));
    }

    public function testFlushesCacheOnAccountDeleted(): void
    {
        Cache::flush();
        $account = new Account(['user_id' => 1, 'user_group_id' => 1]);

        UserScopedCache::remember('1', '1', 'debt-sim-test', fn() => 'cached', 3600);
        $key = 'u:1:g:1:debt-sim-test';
        self::assertTrue(Cache::has($key));

        event('eloquent.deleted: ' . Account::class, $account);

        self::assertFalse(Cache::has($key));
    }
}
