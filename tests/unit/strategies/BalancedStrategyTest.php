<?php

declare(strict_types=1);

namespace Tests\unit\strategies;

use FireflyIII\Modules\AI\Simulations\Strategies\BalancedStrategy;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 */
final class BalancedStrategyTest extends TestCase
{
    public function testProportionalAllocation(): void
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
    }

    public function testResetClearsCache(): void
    {
        $strategy = new BalancedStrategy();

        $debts = [
            ['balance' => 1000.0],
            ['balance' => 500.0],
        ];

        self::assertSame(0, $strategy->selectTargetDebt($debts));
        self::assertSame(1, $strategy->selectTargetDebt($debts));

        $strategy->reset();

        self::assertSame(0, $strategy->selectTargetDebt($debts));
    }
}
