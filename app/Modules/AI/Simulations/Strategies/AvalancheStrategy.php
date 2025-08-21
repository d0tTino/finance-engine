<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

class AvalancheStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'avalanche';
    }

    public function getExplanation(): string
    {
        return 'Pays extra toward the debt with the highest interest rate first.';
    }

    public function reset(): void
    {
    }

    public function selectTargetDebt(array $debts): ?int
    {
        $indices     = array_keys($debts);
        $activeDebts = array_filter($indices, static function ($idx) use ($debts): bool {
            return $debts[$idx]['balance'] > 0.0;
        });
        if ([] === $activeDebts) {
            return null;
        }
        $key     = null;
        $maxRate = -INF;
        foreach ($activeDebts as $idx) {
            if ($debts[$idx]['rate'] > $maxRate) {
                $maxRate = $debts[$idx]['rate'];
                $key     = $idx;
            }
        }

        return $key;
    }
}
