<?php

declare(strict_types=1);

namespace Tests\unit\Modules\AI;

use FireflyIII\Modules\AI\Simulations\Strategies\BalancedStrategy;
use Tests\integration\TestCase;

/**
 * @group unit-test
 * @group ai
 */
final class BalancedStrategyResetTest extends TestCase
{
    public function testResetBetweenSimulations(): void
    {
        $strategy = new BalancedStrategy();

        $debts = [
            ['balance' => 1000.0],
            ['balance' => 500.0],
        ];

        // First simulation alters internal weights
        self::assertSame(0, $strategy->selectTargetDebt($debts));
        self::assertSame(1, $strategy->selectTargetDebt($debts));

        // Reset before running second simulation
        $strategy->reset();

        // After reset, first selection should follow balance proportions
        self::assertSame(0, $strategy->selectTargetDebt($debts));
    }
}
