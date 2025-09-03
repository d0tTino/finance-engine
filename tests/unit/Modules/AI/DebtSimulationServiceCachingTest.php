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
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 0.05],
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

    public function testCachesShuffledAccounts(): void
    {
        Cache::flush();
        $service  = new DebtSimulationService();
        $userId   = '1';
        $groupId  = '1';
        $accounts = [
            ['account_id' => 1, 'balance' => 100.0, 'apr' => 0.05],
            ['account_id' => 2, 'balance' => 50.0, 'apr' => 0.03],
        ];
        $budget     = 50.0;
        $maxOptions = 2;

        $originalAccounts = $accounts;
        $resultA          = $service->simulate($userId, $groupId, $accounts, $budget, $maxOptions);

        $shuffled = $accounts;
        do {
            shuffle($shuffled);
        } while ($shuffled === $originalAccounts);

        $resultB = $service->simulate($userId, $groupId, $shuffled, $budget, $maxOptions);

        self::assertEquals($resultA, $resultB);

        $hash             = hash('sha256', serialize([$originalAccounts, $budget, $maxOptions]));
        $cacheKey         = sprintf('u:%s:g:%s:debt-sim-%s', $userId, $groupId, $hash);
        $hashShuffled     = hash('sha256', serialize([$shuffled, $budget, $maxOptions]));
        $cacheKeyShuffled = sprintf('u:%s:g:%s:debt-sim-%s', $userId, $groupId, $hashShuffled);
        self::assertTrue(Cache::has($cacheKey));
        self::assertFalse(Cache::has($cacheKeyShuffled));
    }
}
