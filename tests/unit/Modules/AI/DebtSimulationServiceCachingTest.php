<?php

/*
 * DebtSimulationServiceCachingTest.php
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
final class DebtSimulationServiceCachingTest extends TestCase
{
    public function testCachesAndFlushesResults(): void
    {
        Cache::flush();

        $service  = new DebtSimulationService();
        $userIdA  = '1';
        $userIdB  = '01';
        $groupId  = '1';
        $accounts = [
            ['id' => 1, 'balance' => 100.0, 'rate' => 5.0],
        ];
        $budget     = 50.0;
        $maxOptions = 2;

        $service->simulate($userIdA, $groupId, $accounts, $budget, $maxOptions);

        $hash       = hash('sha256', serialize([$accounts, $budget, $maxOptions]));
        $cacheKeyA  = sprintf('u:%s:g:%s:debt-sim-%s', $userIdA, $groupId, $hash);
        self::assertTrue(Cache::has($cacheKeyA));

        $service->simulate($userIdB, $groupId, $accounts, $budget, $maxOptions);
        $cacheKeyB = sprintf('u:%s:g:%s:debt-sim-%s', $userIdB, $groupId, $hash);
        self::assertTrue(Cache::has($cacheKeyB));
        self::assertNotEquals($cacheKeyA, $cacheKeyB);

        UserScopedCache::flush($userIdA, $groupId);

        self::assertFalse(Cache::has($cacheKeyA));
        self::assertTrue(Cache::has($cacheKeyB));
    }
}
