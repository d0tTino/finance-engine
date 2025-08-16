<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\Strategies\BalancedStrategy;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 */
final class BalancedStrategyTest extends TestCase
{
    public function testWeightedRoundRobinAcrossMultipleDebts(): void
    {
        $strategy = new BalancedStrategy();

        $debts = [
            ['balance' => 1000.0],
            ['balance' => 500.0],
            ['balance' => 250.0],
        ];

        $sequence = [];
        for ($i = 0; $i < 7; $i++) {
            $sequence[] = $strategy->selectTargetDebt($debts);
        }

        self::assertSame([0, 1, 0, 2, 0, 1, 0], $sequence);

        $strategy = new BalancedStrategy();

        $counts = [0, 0, 0];
        for ($i = 0; $i < 700; $i++) {
            $index = $strategy->selectTargetDebt($debts);
            $counts[$index]++;
        }

        self::assertSame(400, $counts[0]);
        self::assertSame(200, $counts[1]);
        self::assertSame(100, $counts[2]);
    }
}
