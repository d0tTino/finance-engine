<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\Strategies\BalancedStrategy;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 */
final class BalancedStrategyDistributionTest extends TestCase
{
    public function testExtraPaymentsFollowBalanceProportions(): void
    {
        $strategy = new BalancedStrategy();

        $debts = [
            ['balance' => 1000.0],
            ['balance' => 500.0],
        ];

        $counts = [0, 0];
        for ($i = 0; $i < 300; $i++) {
            $index = $strategy->selectTargetDebt($debts);
            $counts[$index]++;
        }

        self::assertSame(200, $counts[0]);
        self::assertSame(100, $counts[1]);

        // Update balances to new proportion 1:4
        $debts[1]['balance'] = 4000.0;

        $adjustedCounts = [0, 0];
        for ($i = 0; $i < 100; $i++) {
            $index = $strategy->selectTargetDebt($debts);
            $adjustedCounts[$index]++;
        }

        self::assertSame(20, $adjustedCounts[0]);
        self::assertSame(80, $adjustedCounts[1]);
    }
}
