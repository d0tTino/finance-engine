<?php

/*
 * DebtSimulationTest.php
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 */

declare(strict_types=1);

namespace Tests\unit;

use FireflyIII\Modules\AI\Simulations\DebtSimulationService;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 *
 * @internal
 */
final class DebtSimulationTest extends TestCase
{
    public function testGeneratesIdenticalPlansForSingleAccount(): void
    {
        $user = $this->createAuthenticatedUser();
        $service = new DebtSimulationService();

        $accounts = [
            ['id' => 1, 'balance' => 1000.0, 'rate' => 10.0],
        ];
        $budget = 200.0;

        $plans = $service->simulate($user->id, 1, $accounts, $budget, 2);

        self::assertCount(2, $plans);

        $mapped = [];
        foreach ($plans as $plan) {
            $mapped[$plan['strategy']] = $plan;
        }

        self::assertSame([1], $mapped['avalanche']['order']);
        self::assertSame([1], $mapped['snowball']['order']);

        self::assertEqualsWithDelta(25.7737936362, $mapped['avalanche']['metrics']['interest'], 0.0001);
        self::assertEquals(6, $mapped['avalanche']['metrics']['months']);
        self::assertEqualsWithDelta($mapped['avalanche']['metrics']['interest'], $mapped['snowball']['metrics']['interest'], 0.0001);
        self::assertSame($mapped['avalanche']['metrics']['months'], $mapped['snowball']['metrics']['months']);

        self::assertSame(0.0, $mapped['avalanche']['deviation_cost']);
        self::assertSame(0.0, $mapped['snowball']['deviation_cost']);
    }

    public function testRanksAvalancheAheadOfSnowballWithMetrics(): void
    {
        $user = $this->createAuthenticatedUser();
        $service = new DebtSimulationService();

        $accounts = [
            ['id' => 1, 'balance' => 1000.0, 'rate' => 10.0],
            ['id' => 2, 'balance' => 500.0, 'rate' => 5.0],
        ];
        $budget = 300.0;

        $plans = $service->simulate($user->id, 1, $accounts, $budget, 2);
        $mapped = [];
        foreach ($plans as $plan) {
            $mapped[$plan['strategy']] = $plan;
        }

        self::assertSame([1, 2], $mapped['avalanche']['order']);
        self::assertSame([2, 1], $mapped['snowball']['order']);

        self::assertEquals(1, $mapped['avalanche']['rank']);
        self::assertEquals(2, $mapped['snowball']['rank']);

        self::assertEqualsWithDelta(28.5355053317, $mapped['avalanche']['metrics']['interest'], 0.0001);
        self::assertEqualsWithDelta(35.6186588887, $mapped['snowball']['metrics']['interest'], 0.0001);
        self::assertEquals(6, $mapped['avalanche']['metrics']['months']);
        self::assertEquals(6, $mapped['snowball']['metrics']['months']);

        self::assertEqualsWithDelta(7.0831535569, $mapped['snowball']['deviation_cost'], 0.0001);
        self::assertEquals(0.0, $mapped['avalanche']['deviation_cost']);

        self::assertSame(DebtSimulationService::RANKING_HEURISTIC, $mapped['avalanche']['ranking_heuristic']);
    }
}
