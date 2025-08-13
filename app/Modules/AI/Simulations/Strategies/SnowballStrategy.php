<?php

declare(strict_types=1);

namespace FireflyIII\Modules\AI\Simulations\Strategies;

class SnowballStrategy implements StrategyInterface
{
    public function getName(): string
    {
        return 'snowball';
    }

    public function getDescription(): string
    {
        return 'Pays extra toward the debt with the smallest balance first to build momentum.';
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
        $key        = null;
        $minBalance = INF;
        foreach ($activeDebts as $idx) {
            if ($debts[$idx]['balance'] < $minBalance) {
                $minBalance = $debts[$idx]['balance'];
                $key        = $idx;
            }
        }

        return $key;
    }
}
