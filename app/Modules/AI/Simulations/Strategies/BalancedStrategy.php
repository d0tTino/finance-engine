<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

class BalancedStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'balanced';
    }

    public function selectTargetDebt(array $debts): ?int
    {
        foreach ($debts as $idx => $debt) {
            if ($debt['balance'] > 0.0) {
                return $idx;
            }
        }

        return null;
    }
}
