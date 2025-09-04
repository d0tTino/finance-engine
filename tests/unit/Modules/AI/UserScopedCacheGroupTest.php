<?php

/*
 * UserScopedCacheGroupTest.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 */

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use FireflyIII\Support\Cache\UserScopedCache;
use Illuminate\Support\Facades\Cache;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 *
 * @internal
 */
final class UserScopedCacheGroupTest extends TestCase
{
    public function testCacheIsolationByGroupAndFlush(): void
    {
        Cache::flush();

        $service  = new DebtSimulationService();
        $userId   = '1';
        $groupIdA = '1';
        $groupIdB = '2';
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 5.0],
        ];
        $budget     = 50.0;
        $maxOptions = 2;

        $service->simulate($userId, $groupIdA, $accounts, $budget, $maxOptions);
        $service->simulate($userId, $groupIdB, $accounts, $budget, $maxOptions);

        $heuristic = config('ai.ranking_heuristic');
        $strategies = config('ai.debt_simulation_strategies', []);
        $hash      = hash('sha256', serialize([$accounts, $budget, $maxOptions, $heuristic, $strategies]));
        $cacheKeyA = sprintf('u:%s:g:%s:debt-sim-%s', $userId, $groupIdA, $hash);
        $cacheKeyB = sprintf('u:%s:g:%s:debt-sim-%s', $userId, $groupIdB, $hash);

        self::assertTrue(Cache::has($cacheKeyA));
        self::assertTrue(Cache::has($cacheKeyB));
        self::assertNotEquals($cacheKeyA, $cacheKeyB);

        UserScopedCache::flush($userId, $groupIdA);

        self::assertFalse(Cache::has($cacheKeyA));
        self::assertTrue(Cache::has($cacheKeyB));
    }
}
